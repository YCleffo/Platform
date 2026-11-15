using System.IO.Pipes;
using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Text.Json;
using Platform.Agent;

namespace Platform.Agent.Service;

/// <summary>
/// Privileged agent host. Runs as a console (`--console`, local development)
/// or as a real Windows service via the SCM protocol implemented directly with
/// P/Invoke (no NuGet dependencies). Real profile operations stay isolated
/// behind TestSessionManager unless the dangerous VM mode is enabled.
/// </summary>
public static class Program
{
    private const string PipeName = "platform-agent";
    private static readonly CancellationTokenSource Shutdown = new();

    public static async Task<int> Main(string[] args)
    {
        if (args.Contains("--migrate-legacy-state", StringComparer.Ordinal))
        {
            try
            {
                var receipt = LegacyInstallation.MigrateDefaultState(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData));
                Console.WriteLine($"Verified state migration completed for {receipt.FileCount} files. Backup retained at {receipt.BackupRoot}.");
                return 0;
            }
            catch (Exception failure) when (failure is IOException or UnauthorizedAccessException or JsonException)
            {
                Console.Error.WriteLine("State migration requires recovery: " + failure.Message);
                return 2;
            }
        }
        AgentConfiguration configuration;
        try
        {
            configuration = LoadConfiguration();
            StateDirectorySecurity.ProtectDirectory(configuration.StateDirectory);
        }
        catch (Exception failure) when (failure is IOException or UnauthorizedAccessException or InvalidOperationException or NotSupportedException or JsonException)
        {
            Console.Error.WriteLine("Agent configuration or state protection failed: " + failure.Message);
            return 2;
        }

        StateJournal state;
        try
        {
            state = StateJournal.Load(configuration.StateDirectory);
        }
        catch (JournalCorruptedException failure)
        {
            Console.Error.WriteLine(failure.Message);
            Console.Error.WriteLine("Refusing to start: unsynchronised documents may still exist.");
            return 2;
        }
        state.BaseUrl = configuration.BaseUrl;
        state.DocumentsRoot ??= configuration.DocumentsRoot;
        state.Record("service.started", "performed", state.RecoveredFromBackup ? "journal_backup" : null,
            state.SessionId);
        state.Save();

        IWindowsSessionManager sessions = configuration.UseRealWindowsOperations
            ? new WindowsSessionManager(Path.Combine(configuration.StateDirectory, "accounts"))
            : new TestSessionManager(configuration.TestProfileRoot);
        HttpClient http;
        try
        {
            http = AgentApiClient.CreateHttpClient(configuration.BaseUrl, configuration.AllowInsecureLocalHttp);
        }
        catch (InvalidOperationException failure)
        {
            Console.Error.WriteLine("Agent endpoint rejected: " + failure.Message);
            return 2;
        }
        var api = new AgentApiClient(http);
        if (state.SessionId is not null && state.StudentId is not null) sessions.BindSession(state.SessionId, state.StudentId);
        using var gate = new SemaphoreSlim(1, 1);
        var loop = new AgentLoop(state, api, sessions, gate: gate);
        var handler = new AgentIpcHandler(state, api, sessions, gate);
        var broker = sessions is WindowsSessionManager native ? new AgentLogonBroker(state, native, gate) : null;

        if (args.Contains("--console", StringComparer.OrdinalIgnoreCase))
        {
            Console.WriteLine($"Platform Agent console mode; state directory: {configuration.StateDirectory}");
            Console.CancelKeyPress += (_, eventArgs) =>
            {
                eventArgs.Cancel = true;
                Shutdown.Cancel();
            };
            var ipc = RunIpcServerAsync(handler);
            var logonIpc = broker is null ? Task.CompletedTask : RunLogonServerAsync(broker);
            try
            {
                await loop.RunAsync(Shutdown.Token);
            }
            finally
            {
                Shutdown.Cancel();
            }
            try
            {
                await Task.WhenAll(ipc, logonIpc);
            }
            catch (OperationCanceledException)
            {
                // Normal shutdown.
            }
            return 0;
        }

        var service = new ScmServiceHost("PlatformAgent");
        Task? loopTask = null;
        Task? ipcTask = null;
        if (!ScmService.Run(service, () =>
        {
            service.ReportState(ServiceState.StartPending);
            loopTask = Task.Run(() => loop.RunAsync(Shutdown.Token));
            ipcTask = Task.Run(() => Task.WhenAll(RunIpcServerAsync(handler),
                broker is null ? Task.CompletedTask : RunLogonServerAsync(broker)));
            service.ReportState(ServiceState.Running);
        }, () =>
        {
            service.ReportState(ServiceState.StopPending);
            Shutdown.Cancel();
            AwaitShutdownAsync(loopTask, ipcTask).GetAwaiter().GetResult();
        }))
        {
            Console.WriteLine("Run the agent with --console for local development.");
            return 1;
        }
        Shutdown.Cancel();
        await AwaitShutdownAsync(loopTask, ipcTask);
        return 0;
    }

    private static async Task AwaitShutdownAsync(params Task?[] tasks)
    {
        var pending = tasks.Where(task => task is not null).Cast<Task>().ToArray();
        if (pending.Length == 0)
        {
            return;
        }
        try
        {
            await Task.WhenAll(pending).WaitAsync(TimeSpan.FromSeconds(10));
        }
        catch (Exception failure) when (failure is OperationCanceledException or TimeoutException
            or JournalCorruptedException)
        {
            // The process is terminating; durable state was already written by the loop.
        }
    }

    private static AgentConfiguration LoadConfiguration()
    {
        var baseUrl = Environment.GetEnvironmentVariable("PLATFORM_AGENT_BASE_URL") ?? "http://127.0.0.1:8080";
        var stateDirectory = LegacyInstallation.ResolveStateDirectory(Environment.GetEnvironmentVariable("PLATFORM_AGENT_STATE_DIR"),
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData));
        var documentsRoot = Environment.GetEnvironmentVariable("PLATFORM_AGENT_DOCUMENTS_ROOT")
            ?? Path.Combine(stateDirectory, "test-documents");
        var testProfileRoot = Path.Combine(stateDirectory, "test-profiles");
        var allowInsecure = Environment.GetEnvironmentVariable("PLATFORM_AGENT_ALLOW_INSECURE_HTTP") == "1";
        var realWindows = Environment.GetEnvironmentVariable("PLATFORM_AGENT_DANGEROUS_WINDOWS_MODE") == "1";
        if (realWindows)
        {
            WindowsSessionManager.DangerousModeEnabled = true;
            WindowsSessionManager.Guard(); // No readiness claim on a machine that lacks independent VM authorization.
        }
        return new AgentConfiguration(baseUrl, stateDirectory, documentsRoot, testProfileRoot, allowInsecure, realWindows);
    }

    /// <summary>
    /// Local named-pipe IPC for the non-privileged UI. The pipe ACL grants access
    /// only to local users, SYSTEM and Administrators. The OS-authenticated client
    /// identity authorizes each operation; JSON never carries an asserted SID.
    /// </summary>
    private static async Task RunIpcServerAsync(AgentIpcHandler handler)
    {
        while (!Shutdown.IsCancellationRequested)
        {
            NamedPipeServerStream? server = null;
            try
            {
                server = CreatePipe();
                await server.WaitForConnectionAsync(Shutdown.Token);
                using var timeout = CancellationTokenSource.CreateLinkedTokenSource(Shutdown.Token);
                timeout.CancelAfter(TimeSpan.FromSeconds(5));
                await HandleUiConnectionAsync(server, handler, timeout.Token);
            }
            catch (OperationCanceledException)
            {
                break;
            }
            catch (IOException)
            {
                // Client disappeared; accept the next one.
            }
            finally
            {
                if (server is not null)
                {
                    await server.DisposeAsync();
                }
            }
        }
    }

    private static NamedPipeServerStream CreatePipe(bool systemOnly = false)
    {
        if (!OperatingSystem.IsWindows())
        {
            return new NamedPipeServerStream(systemOnly ? PipeName + "-logon" : PipeName, PipeDirection.InOut, 1,
                PipeTransmissionMode.Byte, PipeOptions.Asynchronous);
        }
        var security = new PipeSecurity();
        var system = new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null);
        var administrators = new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null);
        var users = new SecurityIdentifier(WellKnownSidType.BuiltinUsersSid, null);
        using var current = WindowsIdentity.GetCurrent();
        security.SetOwner(current.IsSystem ? system : current.User!);
        security.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.NetworkSid, null), PipeAccessRights.FullControl, AccessControlType.Deny));
        security.AddAccessRule(new PipeAccessRule(system, PipeAccessRights.FullControl, AccessControlType.Allow));
        if (!systemOnly)
        {
            security.AddAccessRule(new PipeAccessRule(administrators, PipeAccessRights.FullControl, AccessControlType.Allow));
            security.AddAccessRule(new PipeAccessRule(users, PipeAccessRights.ReadWrite, AccessControlType.Allow));
        }
        return NamedPipeServerStreamAcl.Create(systemOnly ? PipeName + "-logon" : PipeName, PipeDirection.InOut, 1,
            PipeTransmissionMode.Byte, PipeOptions.Asynchronous | PipeOptions.FirstPipeInstance, 4096, 4096, security);
    }

    private static async Task RunLogonServerAsync(AgentLogonBroker broker)
    {
        while (!Shutdown.IsCancellationRequested)
        {
            await using var pipe = CreatePipe(systemOnly: true);
            try
            {
                await pipe.WaitForConnectionAsync(Shutdown.Token);
                using var timeout = CancellationTokenSource.CreateLinkedTokenSource(Shutdown.Token);
                timeout.CancelAfter(TimeSpan.FromSeconds(5));
                using var reader = new StreamReader(pipe, leaveOpen: true);
                using var writer = new StreamWriter(pipe, leaveOpen: true) { AutoFlush = true };
                var input = new System.Text.StringBuilder();
                var buffer = new char[1];
                while (input.Length <= 2048)
                {
                    if (await reader.ReadAsync(buffer.AsMemory(), timeout.Token) == 0) break;
                    if (buffer[0] == '\n') break;
                    input.Append(buffer[0]);
                }
                if (input.Length > 2048) continue;
                var request = JsonSerializer.Deserialize<AgentLogonRequest>(input.ToString(), AgentIpcHandler.JsonOptions);
                if (request is null || !OperatingSystem.IsWindows()) continue;
                AgentIpcPeer? peer = null;
                pipe.RunAsClient(() =>
                {
                    using var identity = WindowsIdentity.GetCurrent();
                    peer = new(identity.User?.Value ?? "", false,
                        identity.User?.IsWellKnown(WellKnownSidType.LocalSystemSid) == true);
                });
                var grant = await broker.AuthorizeAsync(request, peer ?? new("", false, false), timeout.Token);
                await writer.WriteLineAsync(JsonSerializer.Serialize(grant, AgentIpcHandler.JsonOptions).AsMemory(), timeout.Token);
            }
            catch (OperationCanceledException) when (Shutdown.IsCancellationRequested) { break; }
            catch (Exception failure) when (failure is OperationCanceledException or IOException or JsonException
                or UnauthorizedAccessException or AgentIntegrityException or NotSupportedException)
            {
                // Fail closed; never send credentials or internal native failure details on a rejected request.
            }
        }
    }

    private static async Task HandleUiConnectionAsync(NamedPipeServerStream pipe, AgentIpcHandler handler,
        CancellationToken cancellation)
    {
        try
        {
            using var reader = new StreamReader(pipe, leaveOpen: true);
            using var writer = new StreamWriter(pipe, leaveOpen: true) { AutoFlush = true };
            var text = new System.Text.StringBuilder();
            var character = new char[1];
            while (text.Length <= 8192)
            {
                if (await reader.ReadAsync(character.AsMemory(), cancellation) == 0) return;
                if (character[0] == '\n') break;
                text.Append(character[0]);
            }
            AgentIpcRequest? request = null;
            if (text.Length <= 8192)
            {
                try { request = JsonSerializer.Deserialize<AgentIpcRequest>(text.ToString(), AgentIpcHandler.JsonOptions); }
                catch (JsonException) { }
            }
            AgentIpcResponse response;
            if (request is null)
                response = new(1, "", false, "invalid_request", null);
            else
            {
                AgentIpcPeer? peer = null;
                if (OperatingSystem.IsWindows())
                {
                    pipe.RunAsClient(() =>
                    {
                        using var identity = WindowsIdentity.GetCurrent();
                        peer = new(identity.User?.Value ?? "", new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator),
                            identity.User?.IsWellKnown(WellKnownSidType.LocalSystemSid) == true);
                    });
                }
                // Non-Windows developer hosts cannot claim a privileged Windows identity.
                response = await handler.HandleAsync(request, peer ?? new("local-unprivileged", false, false), cancellation);
            }
            await writer.WriteLineAsync(JsonSerializer.Serialize(response, AgentIpcHandler.JsonOptions).AsMemory(), cancellation);
        }
        catch (Exception failure) when (failure is OperationCanceledException or IOException or UnauthorizedAccessException)
        {
            // Client timeout/disconnect/failed impersonation affects only this bounded connection.
        }
    }

}

public sealed record AgentConfiguration(string BaseUrl, string StateDirectory, string DocumentsRoot,
    string TestProfileRoot, bool AllowInsecureLocalHttp, bool UseRealWindowsOperations);

public enum ServiceState : uint
{
    Stopped = 1, StartPending = 2, StopPending = 3, Running = 4, ContinuePending = 5, PausePending = 6, Paused = 7,
}

public sealed class ScmServiceHost
{
    private IntPtr _statusHandle;

    public ScmServiceHost(string name) => Name = name;

    public string Name { get; }

    public void ReportState(ServiceState state, uint exitCode = 0)
    {
        if (_statusHandle == IntPtr.Zero)
        {
            return;
        }
        var status = new ServiceStatus
        {
            ServiceType = 0x10,
            CurrentState = (uint)state,
            ControlsAccepted = state == ServiceState.Running ? 1u : 0u,
            ExitCode = exitCode,
            WaitHint = state is ServiceState.StartPending or ServiceState.StopPending ? 15000u : 0u,
        };
        NativeMethods.SetServiceStatus(_statusHandle, ref status);
    }

    internal void Attach(IntPtr handle) => _statusHandle = handle;
}

/// <summary>Minimal SCM dispatcher: installable Windows service without NuGet packages.</summary>
public static class ScmService
{
    private delegate void ServiceMainCallback(int argc, IntPtr argv);
    private delegate int ControlHandlerCallback(int control, int eventType, IntPtr eventData, IntPtr context);

    private static ScmServiceHost? _host;
    private static Action? _onStart;
    private static Action? _onStop;
    private static IntPtr _statusHandle;
    private static readonly ManualResetEventSlim StopRequested = new(false);
    private static readonly ServiceMainCallback MainCallback = ServiceMain;
    private static readonly ControlHandlerCallback ControlCallback = ControlHandler;

    public static bool Run(ScmServiceHost service, Action onStart, Action onStop)
    {
        _host = service;
        _onStart = onStart;
        _onStop = onStop;
        StopRequested.Reset();
        var table = new ServiceTableEntry[2];
        table[0].Name = service.Name;
        table[0].Procedure = Marshal.GetFunctionPointerForDelegate(MainCallback);
        return NativeMethods.StartServiceCtrlDispatcher(table);
    }

    private static void ServiceMain(int argc, IntPtr argv)
    {
        _statusHandle = NativeMethods.RegisterServiceCtrlHandlerEx(_host!.Name, Marshal.GetFunctionPointerForDelegate(ControlCallback), IntPtr.Zero);
        if (_statusHandle == IntPtr.Zero) return;
        _host.Attach(_statusHandle);
        _host.ReportState(ServiceState.StartPending);
        try
        {
            _onStart!();
            StopRequested.Wait();
            _onStop!();
            _host.ReportState(ServiceState.Stopped);
        }
        catch (Exception)
        {
            // An exception must not cross the unmanaged SCM callback boundary.
            _host.ReportState(ServiceState.Stopped, 1064);
        }
    }

    private static int ControlHandler(int control, int eventType, IntPtr eventData, IntPtr context)
    {
        const int serviceControlStop = 1;
        if (control == serviceControlStop)
        {
            _host!.ReportState(ServiceState.StopPending);
            StopRequested.Set(); // Keep the SCM control handler short; ServiceMain drains tasks.
        }
        return 0;
    }
}

[StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
public struct ServiceTableEntry
{
    [MarshalAs(UnmanagedType.LPWStr)] public string Name;
    public IntPtr Procedure;
}

[StructLayout(LayoutKind.Sequential)]
public struct ServiceStatus
{
    public uint ServiceType;
    public uint CurrentState;
    public uint ControlsAccepted;
    public uint ExitCode;
    public uint ServiceSpecificExitCode;
    public uint CheckPoint;
    public uint WaitHint;
}

internal static class NativeMethods
{
    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    public static extern bool StartServiceCtrlDispatcher(ServiceTableEntry[] table);

    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    public static extern IntPtr RegisterServiceCtrlHandlerEx(string name, IntPtr handler, IntPtr context);

    [DllImport("advapi32.dll", SetLastError = true)]
    public static extern bool SetServiceStatus(IntPtr handle, ref ServiceStatus status);
}

using System.IO.Pipes;
using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Text.Json;
using KiberOne.Agent;

namespace KiberOne.Agent.Service;

/// <summary>
/// Privileged agent host. Runs as a console (`--console`, local development)
/// or as a real Windows service via the SCM protocol implemented directly with
/// P/Invoke (no NuGet dependencies). Real profile operations stay isolated
/// behind TestSessionManager unless the dangerous VM mode is enabled.
/// </summary>
public static class Program
{
    private const string PipeName = "kiberone-agent";
    private static readonly CancellationTokenSource Shutdown = new();

    public static async Task<int> Main(string[] args)
    {
        AgentConfiguration configuration;
        try
        {
            configuration = LoadConfiguration();
            StateDirectorySecurity.ProtectDirectory(configuration.StateDirectory);
        }
        catch (Exception failure) when (failure is IOException or UnauthorizedAccessException or InvalidOperationException)
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
            ? new WindowsSessionManager()
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
        var loop = new AgentLoop(state, api, sessions);

        if (args.Contains("--console", StringComparer.OrdinalIgnoreCase))
        {
            Console.WriteLine($"KiberOne Agent console mode; state directory: {configuration.StateDirectory}");
            Console.CancelKeyPress += (_, eventArgs) =>
            {
                eventArgs.Cancel = true;
                Shutdown.Cancel();
            };
            var ipc = RunIpcServerAsync(state);
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
                await ipc;
            }
            catch (OperationCanceledException)
            {
                // Normal shutdown.
            }
            return 0;
        }

        var service = new ScmServiceHost("KiberOneAgent");
        Task? loopTask = null;
        Task? ipcTask = null;
        if (!ScmService.Run(service, () =>
        {
            service.ReportState(ServiceState.StartPending);
            loopTask = Task.Run(() => loop.RunAsync(Shutdown.Token));
            ipcTask = Task.Run(() => RunIpcServerAsync(state));
            service.ReportState(ServiceState.Running);
        }, () =>
        {
            service.ReportState(ServiceState.StopPending);
            Shutdown.Cancel();
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
        var baseUrl = Environment.GetEnvironmentVariable("KIBERONE_AGENT_BASE_URL") ?? "http://127.0.0.1:8080";
        var stateDirectory = Environment.GetEnvironmentVariable("KIBERONE_AGENT_STATE_DIR")
            ?? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "KiberOne", "Agent");
        var documentsRoot = Environment.GetEnvironmentVariable("KIBERONE_AGENT_DOCUMENTS_ROOT")
            ?? Path.Combine(stateDirectory, "test-documents");
        var testProfileRoot = Path.Combine(stateDirectory, "test-profiles");
        var allowInsecure = Environment.GetEnvironmentVariable("KIBERONE_AGENT_ALLOW_INSECURE_HTTP") == "1";
        var realWindows = Environment.GetEnvironmentVariable("KIBERONE_AGENT_DANGEROUS_WINDOWS_MODE") == "1";
        if (realWindows)
        {
            WindowsSessionManager.DangerousModeEnabled = true;
        }
        return new AgentConfiguration(baseUrl, stateDirectory, documentsRoot, testProfileRoot, allowInsecure, realWindows);
    }

    /// <summary>
    /// Local named-pipe IPC for the non-privileged UI. The pipe ACL grants access
    /// only to interactive users, SYSTEM and Administrators. The protocol accepts
    /// only status reads; the UI can never issue privileged operations.
    /// </summary>
    private static async Task RunIpcServerAsync(StateJournal state)
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
                await HandleUiConnectionAsync(server, state, timeout.Token);
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

    private static NamedPipeServerStream CreatePipe()
    {
        if (!OperatingSystem.IsWindows())
        {
            return new NamedPipeServerStream(PipeName, PipeDirection.InOut, 1,
                PipeTransmissionMode.Byte, PipeOptions.Asynchronous);
        }
        var security = new PipeSecurity();
        var system = new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null);
        var administrators = new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null);
        var users = new SecurityIdentifier(WellKnownSidType.BuiltinUsersSid, null);
        security.AddAccessRule(new PipeAccessRule(system, PipeAccessRights.FullControl, AccessControlType.Allow));
        security.AddAccessRule(new PipeAccessRule(administrators, PipeAccessRights.FullControl, AccessControlType.Allow));
        security.AddAccessRule(new PipeAccessRule(users, PipeAccessRights.ReadWrite, AccessControlType.Allow));
        return NamedPipeServerStreamAcl.Create(PipeName, PipeDirection.InOut, 1,
            PipeTransmissionMode.Byte, PipeOptions.Asynchronous, 4096, 4096, security);
    }

    private static async Task HandleUiConnectionAsync(NamedPipeServerStream pipe, StateJournal state,
        CancellationToken cancellation)
    {
        try
        {
            using var reader = new StreamReader(pipe);
            var command = await reader.ReadLineAsync(cancellation);
            var response = command?.Trim() switch
            {
                "status" => JsonSerializer.Serialize(new
                {
                    deviceId = state.DeviceId,
                    sessionStatus = state.SessionStatus,
                    studentId = state.StudentId,
                    recoveryPending = state.RetryOperations.Count > 0 || state.HasUnfinishedDocuments,
                }),
                "ping" => "{\"status\":\"ok\"}",
                _ => "{\"error\":\"unknown_command\"}",
            };
            using var writer = new StreamWriter(pipe) { AutoFlush = true };
            await writer.WriteLineAsync(response.AsMemory(), cancellation);
        }
        catch (OperationCanceledException)
        {
            // Connection lifetime is bounded; never hold the service open on a stalled client.
        }
        catch (IOException)
        {
            // The UI disconnected.
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
            ControlsAccepted = 1,
            ExitCode = exitCode,
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
    private static readonly ServiceMainCallback MainCallback = ServiceMain;
    private static readonly ControlHandlerCallback ControlCallback = ControlHandler;

    public static bool Run(ScmServiceHost service, Action onStart, Action onStop)
    {
        _host = service;
        _onStart = onStart;
        _onStop = onStop;
        var table = new ServiceTableEntry[2];
        table[0].Name = service.Name;
        table[0].Procedure = Marshal.GetFunctionPointerForDelegate(MainCallback);
        return NativeMethods.StartServiceCtrlDispatcher(table);
    }

    private static void ServiceMain(int argc, IntPtr argv)
    {
        _statusHandle = NativeMethods.RegisterServiceCtrlHandlerEx(_host!.Name, Marshal.GetFunctionPointerForDelegate(ControlCallback), IntPtr.Zero);
        _host.Attach(_statusHandle);
        _host.ReportState(ServiceState.StartPending);
        _onStart!();
    }

    private static int ControlHandler(int control, int eventType, IntPtr eventData, IntPtr context)
    {
        const int serviceControlStop = 1;
        if (control == serviceControlStop)
        {
            _host!.ReportState(ServiceState.StopPending);
            _onStop!();
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

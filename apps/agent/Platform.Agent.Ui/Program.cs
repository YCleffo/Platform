using System.IO.Pipes;
using System.Security.Principal;
using System.Text.Json;
using System.Windows.Forms;
using Platform.Agent;

namespace Platform.Agent.Ui;

/// <summary>
/// Graphical Windows Forms desktop UI for Platform Agent.
/// Connects to the local service over the secure named pipe 'platform-agent'
/// to display live classroom device status, student session state, and enrollment dialog.
/// Also supports --console flag for headless environments.
/// </summary>
public static class Program
{
    [STAThread]
    public static async Task<int> Main(string[] args)
    {
        if (args.Contains("--console"))
        {
            return await RunConsoleLoopAsync();
        }

        ApplicationConfiguration.Initialize();
        Application.Run(new MainForm());
        return 0;
    }

    private static async Task<int> RunConsoleLoopAsync()
    {
        Console.WriteLine("Platform Agent Console UI");
        while (true)
        {
            try
            {
                await using var pipe = new NamedPipeClientStream(".", "platform-agent", PipeDirection.InOut,
                    PipeOptions.Asynchronous, TokenImpersonationLevel.Impersonation);
                using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(8));
                await pipe.ConnectAsync(timeout.Token);
                AgentPipeIdentity.VerifyServer(pipe,
                    Environment.GetEnvironmentVariable("PLATFORM_AGENT_ALLOW_CONSOLE_IPC") == "1");
                using var writer = new StreamWriter(pipe, leaveOpen: true) { AutoFlush = true };
                using var reader = new StreamReader(pipe, leaveOpen: true);
                var request = new AgentIpcRequest(1, Guid.NewGuid().ToString(), "status");
                await writer.WriteLineAsync(JsonSerializer.Serialize(request, AgentIpcHandler.JsonOptions).AsMemory(), timeout.Token);
                var status = await reader.ReadLineAsync(timeout.Token);
                if (status is null || status.Length > 16384) throw new IOException("Invalid service response.");
                var response = JsonSerializer.Deserialize<AgentIpcResponse>(status, AgentIpcHandler.JsonOptions);
                if (response is null || response.Version != 1 || response.RequestId != request.RequestId)
                    throw new IOException("Mismatched service response.");
                Console.WriteLine($"{DateTimeOffset.Now:HH:mm:ss} {JsonSerializer.Serialize(response, AgentIpcHandler.JsonOptions)}");
            }
            catch (OperationCanceledException)
            {
                Console.WriteLine("Service is not running (no response on the named pipe).");
            }
            catch (IOException)
            {
                Console.WriteLine("Service is not running.");
            }
            catch (Exception failure) when (failure is UnauthorizedAccessException or JsonException or System.ComponentModel.Win32Exception)
            {
                Console.WriteLine("The service identity or response could not be verified.");
            }
            await Task.Delay(TimeSpan.FromSeconds(5));
        }
    }
}

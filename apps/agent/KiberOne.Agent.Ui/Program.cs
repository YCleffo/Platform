using System.IO.Pipes;

namespace KiberOne.Agent.Ui;

/// <summary>
/// Non-privileged console UI. It only reads the service status over the local
/// named pipe; it never performs administrative Windows operations itself.
/// </summary>
public static class Program
{
    public static async Task<int> Main(string[] args)
    {
        Console.WriteLine("KiberOne Agent UI");
        while (true)
        {
            try
            {
                await using var pipe = new NamedPipeClientStream(".", "kiberone-agent", PipeDirection.InOut, PipeOptions.Asynchronous);
                await pipe.ConnectAsync(2000);
                using var writer = new StreamWriter(pipe) { AutoFlush = true };
                using var reader = new StreamReader(pipe);
                await writer.WriteLineAsync("status");
                var status = await reader.ReadLineAsync();
                Console.WriteLine($"{DateTimeOffset.Now:HH:mm:ss} {status}");
                pipe.Close();
            }
            catch (TimeoutException)
            {
                Console.WriteLine("Service is not running (no response on the named pipe).");
            }
            catch (IOException)
            {
                Console.WriteLine("Service is not running.");
            }
            await Task.Delay(TimeSpan.FromSeconds(5));
        }
    }
}

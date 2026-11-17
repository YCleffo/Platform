using System.Diagnostics;
using System.Text.Json;
using KiberOne.Agent;

// Narrow, opt-in HTTP scenario. Teacher control is a CLI fixture; all agent and file
// transfers use the production PHP actions and authentication middleware over HTTP.
if (args.Length != 1) throw new ArgumentException("Pass the isolated HTTP fixture.json path.");
using var fixture = JsonDocument.Parse(File.ReadAllText(args[0]));
var config = fixture.RootElement;
var url = config.GetProperty("baseUrl").GetString()!;
if (url != "http://127.0.0.1:18762") throw new InvalidOperationException("Only the disposable local HTTP fixture is allowed.");
var temporary = Path.Combine(Path.GetTempPath(), "kiberone-http-scenario-" + Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(temporary);
var completed = false;
try
{
    using var cancellation = new CancellationTokenSource(TimeSpan.FromMinutes(5));
    using var http = AgentApiClient.CreateHttpClient(url, true);
    var api = new AgentApiClient(http);
    var enrollment = await api.EnrollAsync(config.GetProperty("enrollmentCode").GetString()!, cancellation.Token);
    Require(enrollment.DeviceId == config.GetProperty("deviceId").GetString(), "Enrollment returned another device.");
    var state = StateJournal.Load(Path.Combine(temporary, "state"));
    state.BaseUrl = url;
    state.DeviceId = enrollment.DeviceId;
    state.CredentialId = enrollment.CredentialId;
    state.Credential = enrollment.Credential;
    state.Save();
    var profiles = new TestSessionManager(Path.Combine(temporary, "profiles"));
    var loop = new AgentLoop(state, api, profiles);

    async Task TickUntil(string target)
    {
        for (var count = 0; count < 25; count++)
        {
            try { await loop.TickOnceAsync(cancellation.Token); }
            catch (AgentTransportException) { } // The production loop retries transport failures from its durable journal.
            if (state.SessionStatus == target) return;
            if (state.SessionStatus is "RECOVERY_REQUIRED" or "FAILED"
                || state.SessionStatus == "SYNC_PENDING" && state.LastError?.Code != "network_error")
                throw new InvalidOperationException($"Unexpected {state.SessionStatus}: {state.LastError?.Code}");
            await Task.Delay(50, cancellation.Token);
        }
        throw new InvalidOperationException($"Expected {target}, received {state.SessionStatus}.");
    }

    async Task Start(string student)
    {
        await loop.TickOnceAsync(cancellation.Token); // Explicit readiness before teacher assignment.
        await Control("assign", student);
        await TickUntil(SessionStateMachine.Active);
        Require(state.StudentId == config.GetProperty("students").GetProperty(student).GetProperty("id").GetString(), "Wrong student adopted.");
        Require(!state.WindowsLogonConfirmed, "The test adapter must not claim real Windows logon.");
    }

    async Task End(bool local)
    {
        var root = state.DocumentsRoot!;
        if (local) await api.RequestEndSessionAsync(state.Credential!, state.SessionId!, cancellation.Token);
        else await Control("end");
        await TickUntil(SessionStateMachine.Closed);
        Require(!Directory.Exists(root), "Profile remains after confirmed cleanup.");
    }

    await Start("A");
    var a = config.GetProperty("students").GetProperty("A");
    var aFile = ManifestEngine.ResolvePath(state.DocumentsRoot!, a.GetProperty("initialPath").GetString()!);
    Require(File.ReadAllText(aFile) == a.GetProperty("initialContent").GetString(), "A did not receive the server document.");
    File.WriteAllText(aFile, "A edited over actual HTTP");
    File.WriteAllText(Path.Combine(state.DocumentsRoot!, "A-notes.txt"), "A private notes");
    await End(local: false);
    Console.WriteLine("PASS HTTP A: enrollment, restoration, teacher end, verified upload and cleanup");

    await Start("B");
    var b = config.GetProperty("students").GetProperty("B");
    Require(File.ReadAllText(ManifestEngine.ResolvePath(state.DocumentsRoot!, b.GetProperty("initialPath").GetString()!))
        == b.GetProperty("initialContent").GetString(), "B did not receive its own document.");
    Require(!File.Exists(Path.Combine(state.DocumentsRoot!, "A-notes.txt")), "B received A's document.");
    File.WriteAllText(Path.Combine(state.DocumentsRoot!, "B-notes.txt"), "B private notes");
    await End(local: true);
    Console.WriteLine("PASS HTTP B: isolated Documents, device-authorized local end and confirmed cleanup");

    await Start("A");
    Require(File.ReadAllText(ManifestEngine.ResolvePath(state.DocumentsRoot!, a.GetProperty("initialPath").GetString()!))
        == "A edited over actual HTTP", "A's saved edit was not restored.");
    Require(File.ReadAllText(Path.Combine(state.DocumentsRoot!, "A-notes.txt")) == "A private notes", "A's saved notes were not restored.");
    Require(!File.Exists(Path.Combine(state.DocumentsRoot!, "B-notes.txt")), "A received B's document.");
    await End(local: false);
    Console.WriteLine("PASS HTTP A again: server-persisted edits restored, B files excluded, empty diff confirmed");

    await Start("A");
    var conflictingSession = state.SessionId!;
    File.WriteAllText(Path.Combine(state.DocumentsRoot!, "work.txt"), "A unique conflict edit");
    await Control("browser-edit", "A");
    await Control("end");
    await loop.TickOnceAsync(cancellation.Token);
    Require(state.SessionStatus == SessionStateMachine.RecoveryRequired && Directory.Exists(state.DocumentsRoot), "Unresolved conflict did not preserve local Documents.");
    await Control("resolve");
    await Control("retry");
    await TickUntil(SessionStateMachine.Closed);
    Console.WriteLine("PASS HTTP conflict: teacher-authorized rebase uploaded the local copy before cleanup");

    await Start("A");
    Require(File.ReadAllText(Path.Combine(state.DocumentsRoot!, "work.txt")) == "A browser conflict version", "The chosen browser version changed.");
    var conflictHash = ManifestEngine.HashBytes(System.Text.Encoding.UTF8.GetBytes("A unique conflict edit"));
    Require(File.ReadAllText(ManifestEngine.ResolvePath(state.DocumentsRoot!, $"Conflicts/{conflictingSession}/{conflictHash}.txt"))
        == "A unique conflict edit", "The unique student version was not restored from Storage.");
    await End(local: false);
    Console.WriteLine("PASS HTTP conflict copies: both independent versions restored in the next session");

    await Control("revoke");
    try { await api.HeartbeatAsync(state.Credential!, cancellation.Token); throw new InvalidOperationException("Revoked credential was accepted."); }
    catch (AgentApiException failure) when (failure.StatusCode == 401) { }
    Console.WriteLine("PASS HTTP revoked agent credential is rejected");
    completed = true;
}
finally
{
    // This directory was generated immediately above and is confined to the system temp directory.
    var resolved = Path.GetFullPath(temporary);
    Require(resolved.StartsWith(Path.GetFullPath(Path.GetTempPath()), StringComparison.OrdinalIgnoreCase)
        && Path.GetFileName(resolved).StartsWith("kiberone-http-scenario-", StringComparison.Ordinal), "Unsafe test cleanup path.");
    if (completed && Directory.Exists(resolved)) Directory.Delete(resolved, true);
    else if (!completed) Console.Error.WriteLine("Failed scenario journal and Documents preserved at: " + resolved);
}

static void Require(bool condition, string message)
{
    if (!condition) throw new InvalidOperationException(message);
}

static async Task Control(params string[] arguments)
{
    var start = new ProcessStartInfo("docker") { UseShellExecute = false, CreateNoWindow = true,
        RedirectStandardOutput = true, RedirectStandardError = true };
    foreach (var value in new[] { "exec", "platform-classroom02-http", "php", "/classroom/tests/Classroom/http-fixture.php" }.Concat(arguments))
        start.ArgumentList.Add(value);
    using var process = Process.Start(start) ?? throw new IOException("Could not start test fixture control.");
    var outputTask = process.StandardOutput.ReadToEndAsync();
    var errorTask = process.StandardError.ReadToEndAsync();
    await process.WaitForExitAsync();
    var output = await outputTask;
    var error = await errorTask;
    if (process.ExitCode != 0) throw new InvalidOperationException("Test fixture control failed: " + error);
    using var document = JsonDocument.Parse(output); // Validate success output without logging fixture credentials.
}

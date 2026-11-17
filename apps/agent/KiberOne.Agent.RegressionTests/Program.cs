using System.Net;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using KiberOne.Agent;

namespace KiberOne.Agent.RegressionTests;

public static class Program
{
    private const string OwnerSid = "S-1-5-21-100-100-100-1001";
    private const string OtherSid = "S-1-5-21-100-100-100-1002";
    private const string Session = "01961be3-2d44-7000-8000-000000000001";
    private const string Student = "01961be3-2d44-7000-8000-000000000002";
    private static readonly AgentIpcPeer Owner = new(OwnerSid, false, false);
    private static readonly AgentIpcPeer Other = new(OtherSid, false, false);
    private static readonly AgentIpcPeer Admin = new(OtherSid, true, false);

    public static async Task<int> Main()
    {
        var cases = new (string Name, Func<Task> Body)[]
        {
            ("invalid protocol cannot invoke API", InvalidProtocol),
            ("IPC rejects JSON identity injection", RejectIdentityInjection),
            ("unknown command cannot invoke API", UnknownCommand),
            ("ordinary user cannot enroll or confirm", EnrollmentAuthorization),
            ("admin enrollment invokes actual API and persists credential", AdminEnrollment),
            ("enrollment cannot replace recovery or existing enrollment", EnrollmentPreservesRecovery),
            ("another SID cannot end current session", SessionEndAuthorization),
            ("administrator cannot end a different session", SessionMismatch),
            ("managed SID requests end through actual agent endpoint", ManagedSessionEnd),
            ("offline end request preserves active state and files", OfflineEndPreservesState),
            ("recovery session rejects local end request", RecoverySessionCannotEnd),
            ("unrelated user sees no student identity or private error", ReadStatusRedaction),
            ("IPC available actions match actual enrollment guards", EnrollmentActionConsistency),
            ("account names distinguish identical ID prefixes", AccountIdentityUniqueness),
            ("test profiles isolate A to B to A sessions", TestProfileIsolation),
            ("native profile operation is disabled by default", NativeGuardRejects),
            ("logon broker rejects non-SYSTEM peers before native operations", LogonBrokerAuthorization),
            ("state progress uses the backend camelCase wire contract", StateProgressWireContract),
            ("server manifest reads the documented sizeBytes field", ServerManifestWireContract),
            ("HTTP timeouts retry while caller cancellation stops", TimeoutIsNotShutdown),
        };
        var failures = 0;
        foreach (var (name, body) in cases)
        {
            try { await body(); Console.WriteLine("PASS " + name); }
            catch (Exception failure) { failures++; Console.WriteLine("FAIL " + name + ": " + failure.Message); }
        }
        Console.WriteLine($"Classroom agent regression: {cases.Length - failures}/{cases.Length} passed.");
        return failures == 0 ? 0 : 1;
    }

    private static async Task InvalidProtocol()
    {
        using var fixture = new Fixture();
        foreach (var request in new[] { new AgentIpcRequest(2, Guid.NewGuid().ToString(), "enroll", "code"), new AgentIpcRequest(1, "invalid", "enroll", "code") })
            Error("invalid_request", await fixture.Handler.HandleAsync(request, Admin, default));
        Error("invalid_request", await fixture.Handler.HandleAsync(Request("enroll", "code"), new AgentIpcPeer("", true, false), default));
        Equal(0, fixture.Http.Calls.Count);
    }

    private static Task RejectIdentityInjection()
    {
        try
        {
            _ = JsonSerializer.Deserialize<AgentIpcRequest>("""{"version":1,"requestId":"01961be3-2d44-7000-8000-000000000001","command":"end_session","isAdministrator":true,"sid":"S-1-5-18"}""", AgentIpcHandler.JsonOptions);
            throw new InvalidOperationException("Caller-controlled identity fields must be rejected.");
        }
        catch (JsonException) { }
        return Task.CompletedTask;
    }

    private static async Task UnknownCommand()
    {
        using var fixture = new Fixture();
        Error("unknown_command", await fixture.Handler.HandleAsync(Request("run_powershell", "Remove-Item"), Admin, default));
        Equal(0, fixture.Http.Calls.Count);
    }

    private static async Task EnrollmentAuthorization()
    {
        using var fixture = new Fixture();
        foreach (var command in new[] { "enroll", "confirm_enrollment" })
            Error("forbidden", await fixture.Handler.HandleAsync(Request(command, "code"), Other, default));
        Equal(0, fixture.Http.Calls.Count);
    }

    private static async Task AdminEnrollment()
    {
        using var fixture = new Fixture();
        fixture.Http.Response = """{"data":{"deviceId":"device-1","credentialId":"credential-1","credential":"private-device-token"}}""";
        var reply = await fixture.Handler.HandleAsync(Request("enroll", "authorized-one-time-code"), Admin, default);
        True(reply.Ok, reply.Error);
        Equal("api/device-agent/enroll", fixture.Http.Calls.Single().Path);
        Equal("authorized-one-time-code", JsonDocument.Parse(fixture.Http.Calls.Single().Body).RootElement.GetProperty("code").GetString());
        Equal("private-device-token", StateJournal.Load(fixture.Root).Credential);
        var publicReply = JsonSerializer.Serialize(reply, AgentIpcHandler.JsonOptions);
        True(!publicReply.Contains("private-device-token", StringComparison.Ordinal), "Device secret was returned over UI IPC.");
    }

    private static async Task EnrollmentPreservesRecovery()
    {
        using var fixture = new Fixture();
        fixture.State.RetryOperations.Add("sync_out:12");
        Error("enrollment_blocked", await fixture.Handler.HandleAsync(Request("enroll", "code"), Admin, default));
        fixture.State.RetryOperations.Clear();
        fixture.State.SessionId = Session;
        Error("enrollment_blocked", await fixture.Handler.HandleAsync(Request("enroll", "code"), Admin, default));
        fixture.State.SessionId = null;
        fixture.State.Credential = "existing-token";
        Error("enrollment_blocked", await fixture.Handler.HandleAsync(Request("enroll", "code"), Admin, default));
        Equal("existing-token", fixture.State.Credential);
        Equal(0, fixture.Http.Calls.Count);
    }

    private static async Task SessionEndAuthorization()
    {
        using var fixture = new Fixture(active: true);
        Error("forbidden", await fixture.Handler.HandleAsync(Request("end_session", session: Session), Other, default));
        Equal(0, fixture.Http.Calls.Count);
        Equal(null, fixture.State.DesiredStatus);
    }

    private static async Task SessionMismatch()
    {
        using var fixture = new Fixture(active: true);
        Error("session_mismatch", await fixture.Handler.HandleAsync(Request("end_session", session: Guid.NewGuid().ToString()), Admin, default));
        Equal(0, fixture.Http.Calls.Count);
    }

    private static async Task ManagedSessionEnd()
    {
        using var fixture = new Fixture(active: true);
        fixture.Http.Response = "{\"data\":{\"sessionId\":\"" + Session + "\",\"ending\":true}}";
        var reply = await fixture.Handler.HandleAsync(Request("end_session", session: Session), Owner, default);
        True(reply.Ok, reply.Error);
        Equal($"api/device-agent/session/{Session}/end", fixture.Http.Calls.Single().Path);
        Equal("device-token", fixture.Http.Calls.Single().Token);
        Equal(SessionStateMachine.Ending, fixture.State.DesiredStatus);
        Equal(SessionStateMachine.Ending, StateJournal.Load(fixture.Root).DesiredStatus);
        Equal(0, fixture.Sessions.SystemCalls, "IPC must ask the authenticated backend before local destructive actions.");
    }

    private static async Task OfflineEndPreservesState()
    {
        using var fixture = new Fixture(active: true);
        fixture.Http.Offline = true;
        fixture.State.DocumentsRoot = Path.Combine(fixture.Root, "Documents");
        Directory.CreateDirectory(fixture.State.DocumentsRoot);
        var work = Path.Combine(fixture.State.DocumentsRoot, "work.txt");
        await File.WriteAllTextAsync(work, "only unsaved copy");
        Error("service_operation_failed", await fixture.Handler.HandleAsync(Request("end_session", session: Session), Owner, default));
        Equal(SessionStateMachine.Active, fixture.State.SessionStatus);
        Equal(null, fixture.State.DesiredStatus);
        Equal("only unsaved copy", await File.ReadAllTextAsync(work));
        Equal(0, fixture.Sessions.SystemCalls);
    }

    private static async Task RecoverySessionCannotEnd()
    {
        using var fixture = new Fixture(active: true);
        fixture.State.SessionStatus = SessionStateMachine.SyncPending;
        Error("session_not_active", await fixture.Handler.HandleAsync(Request("end_session", session: Session), Owner, default));
        Equal(0, fixture.Http.Calls.Count);
    }

    private static async Task ReadStatusRedaction()
    {
        using var fixture = new Fixture(active: true);
        fixture.State.LastError = new SessionError("private", "student-private-error", DateTimeOffset.UtcNow);
        var other = await fixture.Handler.HandleAsync(Request("status"), Other, default);
        True(other.Ok);
        Equal(null, other.State!.SessionId);
        Equal(null, other.State.StudentId);
        Equal(null, other.State.LastError);
        True(!other.State.CanEndSession);
        var own = await fixture.Handler.HandleAsync(Request("status"), Owner, default);
        Equal(Student, own.State!.StudentId);
        Equal("private", own.State.LastError!.Code);
    }

    private static async Task EnrollmentActionConsistency()
    {
        using var fixture = new Fixture();
        fixture.State.SessionId = Session;
        fixture.State.SessionStatus = SessionStateMachine.Preparing;
        var status = await fixture.Handler.HandleAsync(Request("status"), Admin, default);
        True(!status.State!.CanEnroll, "The UI must not offer enrollment while a session exists.");
        fixture.State.SessionId = null;
        fixture.State.DocumentsRoot = Path.Combine(fixture.Root, "Documents");
        status = await fixture.Handler.HandleAsync(Request("status"), Admin, default);
        True(!status.State!.CanEnroll, "The UI must not offer enrollment while unsynchronized Documents exist.");
    }

    private static Task AccountIdentityUniqueness()
    {
        var first = WindowsSessionManager.ResolveUsername("01961be3-2d44-7000-8000-000000000001");
        var second = WindowsSessionManager.ResolveUsername("01961be3-2d44-7000-8000-000000000002");
        True(first != second, "Full DeviceSession IDs sharing prefixes must have different accounts.");
        True(first.Length <= 20 && second.Length <= 20, "Windows account name limit exceeded.");
        Equal(first, WindowsSessionManager.ResolveUsername("01961be3-2d44-7000-8000-000000000001"));
        return Task.CompletedTask;
    }

    private static async Task TestProfileIsolation()
    {
        var root = Path.Combine(Path.GetTempPath(), "classroom-profile-regression-" + Guid.NewGuid().ToString("N"));
        try
        {
            var sessions = new TestSessionManager(root);
            sessions.BindSession(Session, "A");
            var firstA = await sessions.PrepareProfileAsync("A", default);
            await File.WriteAllTextAsync(Path.Combine(firstA, "work.txt"), "A unsaved");
            sessions.BindSession(Guid.NewGuid().ToString(), "B");
            var b = await sessions.PrepareProfileAsync("B", default);
            sessions.BindSession(Guid.NewGuid().ToString(), "A");
            var secondA = await sessions.PrepareProfileAsync("A", default);
            True(firstA != b && b != secondA && firstA != secondA, "DeviceSessions require separate profile directories.");
            True(!File.Exists(Path.Combine(b, "work.txt")) && !File.Exists(Path.Combine(secondA, "work.txt")), "Previous session work leaked.");
            Equal("A unsaved", await File.ReadAllTextAsync(Path.Combine(firstA, "work.txt")));
            True(!await sessions.IsWindowsLogonConfirmedAsync(default), "The test adapter must not claim real Windows logon.");
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
    }

    private static async Task NativeGuardRejects()
    {
        var enabled = WindowsSessionManager.DangerousModeEnabled;
        try
        {
            WindowsSessionManager.DangerousModeEnabled = false;
            try
            {
                await new WindowsSessionManager().PrepareProfileAsync(Student, default);
                throw new InvalidOperationException("Native profile operation ran without VM authorization.");
            }
            catch (NotSupportedException) { }
        }
        finally { WindowsSessionManager.DangerousModeEnabled = enabled; }
    }

    private static async Task LogonBrokerAuthorization()
    {
        using var fixture = new Fixture(active: true);
        using var gate = new SemaphoreSlim(1, 1);
        var broker = new AgentLogonBroker(fixture.State, new WindowsSessionManager(Path.Combine(fixture.Root, "accounts")), gate);
        foreach (var peer in new[] { Owner, Admin, new AgentIpcPeer(OwnerSid, true, true), new AgentIpcPeer("S-1-5-18", true, false) })
        {
            try
            {
                await broker.AuthorizeAsync(new AgentLogonRequest(1, Guid.NewGuid().ToString(), Session), peer, default);
                throw new InvalidOperationException("Logon credentials were offered to a non-SYSTEM pipe peer.");
            }
            catch (UnauthorizedAccessException) { }
        }
        True(!Directory.Exists(Path.Combine(fixture.Root, "accounts")), "Denied logon request must not create native state.");
    }

    private static AgentIpcRequest Request(string command, string? code = null, string? session = null) => new(1, Guid.NewGuid().ToString(), command, code, session);
    private static async Task StateProgressWireContract()
    {
        using var transport = new StubHttp { Response = JsonSerializer.Serialize(new { data = new { sessionId = Session, status = "SYNCING_IN" } }) };
        using var http = new HttpClient(transport) { BaseAddress = new Uri("http://127.0.0.1/") };
        await new AgentApiClient(http).ReportSessionStateAsync("credential", Session, "SYNCING_IN", default,
            new { profilePrepared = true, testAdapter = true }, new SessionProgress("sync_in", 1, 2));
        using var body = JsonDocument.Parse(transport.Calls.Single().Body);
        var progress = body.RootElement.GetProperty("progress");
        Equal("sync_in", progress.GetProperty("stage").GetString());
        Equal(1, progress.GetProperty("completed").GetInt32());
        Equal(2, progress.GetProperty("total").GetInt32());
        True(!progress.TryGetProperty("Stage", out _), "PascalCase progress is rejected by the real PHP API.");
    }

    private static async Task ServerManifestWireContract()
    {
        using var transport = new StubHttp { Response = """{"data":{"version":0,"entries":[{"fileId":"01961be3-2d44-7000-8000-000000000001","path":"work.txt","versionId":"01961be3-2d44-7000-8000-000000000002","version":1,"sizeBytes":26,"sha256":"b198d02b0b916523b0aabbd22c820acfdfb73020c0cd26f707a786ad7b69427e"}]}}""" };
        using var http = new HttpClient(transport) { BaseAddress = new Uri("http://127.0.0.1/") };
        var manifest = await new AgentApiClient(http).GetManifestAsync("credential", default);
        Equal(26L, manifest.Entries.Single().Size);
        Equal("work.txt", manifest.Entries.Single().Path);
    }

    private static async Task TimeoutIsNotShutdown()
    {
        using var transport = new StubHttp { Timeout = true };
        using var http = new HttpClient(transport) { BaseAddress = new Uri("http://127.0.0.1/") };
        var api = new AgentApiClient(http);
        try { await api.EnrollAsync("code", default); throw new InvalidOperationException("Timeout was accepted."); }
        catch (AgentTransportException) { }
        using var canceled = new CancellationTokenSource();
        canceled.Cancel();
        try { await api.EnrollAsync("code", canceled.Token); throw new InvalidOperationException("Caller cancellation was ignored."); }
        catch (OperationCanceledException) { }
    }
    private static void Error(string expected, AgentIpcResponse reply) { True(!reply.Ok); Equal(expected, reply.Error); }
    private static void True(bool value, string? message = null) { if (!value) throw new InvalidOperationException(message ?? "Expected true."); }
    private static void Equal<T>(T expected, T actual, string? message = null) { if (!EqualityComparer<T>.Default.Equals(expected, actual)) throw new InvalidOperationException(message ?? $"Expected {expected}, got {actual}."); }

    private sealed class Fixture : IDisposable
    {
        public string Root { get; } = Path.Combine(Path.GetTempPath(), "classroom-ipc-regression-" + Guid.NewGuid().ToString("N"));
        public StateJournal State { get; }
        public StubHttp Http { get; } = new();
        public StubSessions Sessions { get; } = new();
        public AgentIpcHandler Handler { get; }
        private readonly HttpClient _client;
        private readonly SemaphoreSlim _gate = new(1, 1);
        public Fixture(bool active = false)
        {
            State = StateJournal.Load(Root);
            if (active) { State.SessionId = Session; State.StudentId = Student; State.SessionStatus = SessionStateMachine.Active; State.Credential = "device-token"; }
            _client = new HttpClient(Http) { BaseAddress = new Uri("http://127.0.0.1/") };
            Handler = new AgentIpcHandler(State, new AgentApiClient(_client), Sessions, _gate);
        }
        public void Dispose() { _client.Dispose(); _gate.Dispose(); if (Directory.Exists(Root)) Directory.Delete(Root, true); }
    }

    private sealed class StubHttp : HttpMessageHandler
    {
        public List<(string Path, string? Token, string Body)> Calls { get; } = new();
        public string Response { get; set; } = "{\"data\":{}}";
        public bool Offline { get; set; }
        public bool Timeout { get; set; }
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellation)
        {
            Calls.Add((request.RequestUri!.AbsolutePath.TrimStart('/'), request.Headers.Authorization?.Parameter, request.Content is null ? "" : await request.Content.ReadAsStringAsync(cancellation)));
            if (Offline) throw new HttpRequestException("simulated offline");
            if (Timeout) throw new TaskCanceledException("simulated HTTP timeout");
            return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(Response, Encoding.UTF8, "application/json") };
        }
    }

    private sealed class StubSessions : IWindowsSessionManager
    {
        public string? ManagedSid => OwnerSid;
        public bool IsTestAdapter => true;
        public int SystemCalls { get; private set; }
        public Task<string> PrepareProfileAsync(string studentId, CancellationToken cancellation) { SystemCalls++; throw new InvalidOperationException("Unexpected profile preparation."); }
        public Task EndUserSessionAsync(string studentId, CancellationToken cancellation) { SystemCalls++; throw new InvalidOperationException("Unexpected direct signout."); }
        public Task CleanupProfileAsync(string studentId, CancellationToken cancellation) { SystemCalls++; throw new InvalidOperationException("Unexpected cleanup."); }
        public Task<bool> HasRecoveryStateAsync(string studentId, CancellationToken cancellation) => Task.FromResult(false);
    }
}

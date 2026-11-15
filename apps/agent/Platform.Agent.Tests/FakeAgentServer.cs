using System.Net;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace Platform.Agent.Tests;

/// <summary>
/// In-memory platform double implementing the DeviceManagement/Storage agent
/// protocol. It lets the real AgentLoop run against controlled network and
/// storage failures without touching Windows accounts or a real database.
/// </summary>
public sealed class FakeAgentServer
{
    private sealed class FakeSession
    {
        public string Id { get; init; } = Guid.NewGuid().ToString();
        public required string StudentId { get; init; }
        public string Status { get; set; } = "AUTHORIZED";
        public string? DesiredStatus { get; set; } = "AUTHORIZED";
    }

    private sealed class FakeCommand
    {
        public string Id { get; init; } = Guid.NewGuid().ToString();
        public required string Kind { get; init; }
        public required JsonObject Payload { get; init; }
        public required DateTimeOffset ExpiresAt { get; init; }
        public bool Acknowledged { get; set; }
    }

    private sealed record FakeFile(byte[] Content, string Sha256, long Size, int Version);

    private sealed class FakeTicket
    {
        public string Id { get; init; } = Guid.NewGuid().ToString();
        public string Token { get; init; } = Convert.ToHexStringLower(RandomNumberGenerator.GetBytes(24));
        public required string Purpose { get; init; }
        public required string StudentId { get; init; }
        public required string Path { get; init; }
        public string? ExpectedSha256 { get; init; }
        public long? ExpectedSize { get; init; }
        public byte[]? Snapshot { get; init; }
        public string? SnapshotSha256 { get; init; }
        public DateTimeOffset ExpiresAt { get; init; } = DateTimeOffset.UtcNow.AddMinutes(10);
        public bool Used { get; set; }
    }

    private sealed class FakeTransfer
    {
        public string Id { get; } = Guid.NewGuid().ToString();
        public required FakeTicket Ticket { get; init; }
        public byte[]? Bytes { get; set; }
        public ApiUploadResult? Result { get; set; }
    }
    private readonly Dictionary<string, FakeTransfer> _transfers = new();

    private sealed class FakeEntry
    {
        public required string Path { get; init; }
        public required string Operation { get; init; }
        public string? Sha256 { get; init; }
        public long Size { get; init; }
        public string? BaseSha256 { get; init; }
        public string State { get; set; } = "expected";
        public string? Resolution { get; set; }
        public FakeTicket? Ticket { get; set; }
    }

    private sealed class FakeManifest
    {
        public string Id { get; init; } = Guid.NewGuid().ToString();
        public required long Version { get; init; }
        public required List<FakeEntry> Entries { get; init; }
        public string Status { get; set; } = "pending";
        public string? SnapshotSha256 { get; set; }
    }

    private sealed record SnapshotEntry(string Path, string Sha256, long Size);

    private sealed class StudentSpace
    {
        public Dictionary<string, FakeFile> Files { get; } = new(StringComparer.OrdinalIgnoreCase);
        public Dictionary<long, FakeManifest> Manifests { get; } = new();
        public long ConfirmedVersion { get; set; }
        public HashSet<string> Deleted { get; } = new(StringComparer.OrdinalIgnoreCase);
    }

    private readonly Dictionary<string, StudentSpace> _spaces = new(StringComparer.OrdinalIgnoreCase);
    private readonly List<FakeCommand> _commands = new();
    private readonly Dictionary<string, FakeTicket> _tickets = new();
    private readonly Dictionary<string, int> _failures = new();
    private FakeSession? _session;
    private long _manifestSequence;
    private bool _rebaseAuthorized;

    public string DeviceId { get; } = Guid.NewGuid().ToString();
    public string Credential { get; } = Convert.ToHexStringLower(RandomNumberGenerator.GetBytes(32));
    public List<string> Transitions { get; } = new();
    public bool DeliverExpiredCommands { get; set; }
    public int FailConfirmCount { get; set; }

    public HttpClient CreateHttpClient() => new(new Handler(this))
    {
        BaseAddress = new Uri("http://127.0.0.1/"),
        Timeout = TimeSpan.FromSeconds(10),
    };

    public void FailNext(string operation, int count = 1) => _failures[operation] = count;

    public void PutStudentFile(string studentId, string path, string content)
    {
        var space = Space(studentId);
        var bytes = Encoding.UTF8.GetBytes(content);
        var version = space.Files.TryGetValue(path, out var existing) ? existing.Version + 1 : 1;
        space.Files[path] = new FakeFile(bytes, Hash(bytes), bytes.LongLength, version);
        space.Deleted.Remove(path);
    }

    public void DeleteStudentFile(string studentId, string path)
    {
        Space(studentId).Files.Remove(path);
        Space(studentId).Deleted.Add(path);
    }

    public void ResolveConflictsKeepServer()
    {
        foreach (var manifest in Space(_session!.StudentId).Manifests.Values.Where(manifest => manifest.Status == "pending"))
            foreach (var entry in manifest.Entries.Where(entry => entry.State == "conflict")) entry.Resolution = "keep_server";
    }

    public void AuthorizeRecovery()
    {
        _rebaseAuthorized = true;
        _session!.DesiredStatus = "ENDING";
        Queue("end_session", new JsonObject { ["sessionId"] = _session.Id });
    }

    public string? ReadStudentFile(string studentId, string path) =>
        _spaces.TryGetValue(studentId, out var space) && space.Files.TryGetValue(path, out var file)
            ? Encoding.UTF8.GetString(file.Content) : null;

    public bool StudentHasFile(string studentId, string path) =>
        _spaces.TryGetValue(studentId, out var space) && space.Files.ContainsKey(path);

    public string StartSession(string studentId)
    {
        if (_session is not null && _session.Status is not ("CLOSED" or "FAILED"))
        {
            throw new InvalidOperationException("A fake session is already active.");
        }
        _session = new FakeSession { StudentId = studentId };
        _rebaseAuthorized = false;
        Queue("start_session", new JsonObject { ["sessionId"] = _session.Id, ["studentId"] = studentId });
        return _session.Id;
    }

    public void RequestEnd()
    {
        if (_session is null)
        {
            throw new InvalidOperationException("No active fake session.");
        }
        if (_session.DesiredStatus == "ENDING")
        {
            return;
        }
        _session.DesiredStatus = "ENDING";
        Queue("end_session", new JsonObject { ["sessionId"] = _session.Id });
    }

    public void QueueExpiredEnd()
    {
        if (_session is null)
        {
            throw new InvalidOperationException("No active fake session.");
        }
        _commands.Add(new FakeCommand
        {
            Kind = "end_session",
            Payload = new JsonObject { ["sessionId"] = _session.Id },
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(-1),
        });
        DeliverExpiredCommands = true;
    }

    public string? SessionStatus => _session?.Status;
    public string? DesiredStatus => _session?.DesiredStatus;
    public long ConfirmedVersion(string studentId) => Space(studentId).ConfirmedVersion;

    private StudentSpace Space(string studentId)
    {
        if (!_spaces.TryGetValue(studentId, out var space))
        {
            space = new StudentSpace();
            _spaces[studentId] = space;
        }
        return space;
    }

    private void Queue(string kind, JsonObject payload) => _commands.Add(new FakeCommand
    {
        Kind = kind,
        Payload = payload,
        ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(15),
    });

    private sealed class Handler(FakeAgentServer server) : HttpMessageHandler
    {
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request,
            CancellationToken cancellationToken)
        {
            await Task.Yield();
            var operation = Operation(request);
            if (server._failures.TryGetValue(operation, out var remaining) && remaining > 0)
            {
                server._failures[operation] = remaining - 1;
                throw new HttpRequestException("Simulated network failure.");
            }
            return await server.HandleAsync(request, cancellationToken);
        }
    }

    private static string Operation(HttpRequestMessage request)
    {
        var path = request.RequestUri!.AbsolutePath;
        var method = request.Method.Method;
        return (method, path) switch
        {
            ("POST", "/api/device-agent/heartbeat") => "heartbeat",
            ("GET", "/api/device-agent/commands") => "commands",
            (_, var p) when p.EndsWith("/ack", StringComparison.Ordinal) => "ack",
            (_, var p) when p.Contains("/session/", StringComparison.Ordinal) && p.EndsWith("/state", StringComparison.Ordinal) => "state",
            ("POST", "/api/device-agent/storage/manifest") => "manifest",
            ("POST", "/api/device-agent/storage/manifest/rebase") => "rebase",
            ("GET", "/api/device-agent/storage/manifest") => "manifest_read",
            ("POST", "/api/device-agent/storage/ticket") => "ticket",
            ("PUT", var p) when p.StartsWith("/test-objects/", StringComparison.Ordinal) => "upload",
            ("GET", var p) when p.StartsWith("/test-objects/", StringComparison.Ordinal) => "download",
            ("PUT", "/api/device-agent/storage/file") => "upload",
            ("GET", "/api/device-agent/storage/file") => "download",
            ("POST", "/api/device-agent/storage/sync") => "confirm",
            ("POST", "/api/device-agent/events") => "events",
            _ => "other",
        };
    }

    private async Task<HttpResponseMessage> HandleAsync(HttpRequestMessage request,
        CancellationToken cancellationToken)
    {
        var path = request.RequestUri!.AbsolutePath;
        var method = request.Method.Method;
        if (path.StartsWith("/api/device-agent/storage/transfers", StringComparison.Ordinal))
            return await TransferAsync(request, cancellationToken);
        if (path.StartsWith("/test-objects/", StringComparison.Ordinal))
        {
            if (request.Headers.Authorization is not null) throw new InvalidOperationException("Device token leaked to object storage.");
            var transfer = _transfers[path.Split('/')[2]];
            if (method == "PUT")
            {
                transfer.Bytes = await request.Content!.ReadAsByteArrayAsync(cancellationToken);
                return new HttpResponseMessage(HttpStatusCode.OK);
            }
            var bytes = transfer.Ticket.Snapshot!;
            var from = request.Headers.Range?.Ranges.First().From ?? 0;
            var response = new HttpResponseMessage(from == 0 ? HttpStatusCode.OK : HttpStatusCode.PartialContent)
            { Content = new ByteArrayContent(bytes[(int)from..]) };
            if (from > 0) response.Content.Headers.ContentRange = new System.Net.Http.Headers.ContentRangeHeaderValue(from, bytes.LongLength - 1, bytes.LongLength);
            return response;
        }
        if (method == "POST" && path == "/api/device-agent/heartbeat")
        {
            var active = _session is null or { Status: "CLOSED" or "FAILED" } ? null : SessionDto();
            return Data(new { status = "ok", session = active });
        }
        if (method == "GET" && path == "/api/device-agent/commands")
        {
            var now = DateTimeOffset.UtcNow;
            var commands = _commands
                .Where(command => !command.Acknowledged && (DeliverExpiredCommands || command.ExpiresAt > now))
                .Select(command => new
                {
                    id = command.Id,
                    kind = command.Kind,
                    payload = command.Payload,
                    expiresAt = command.ExpiresAt.ToString("O"),
                })
                .ToArray();
            return Data(new { items = commands });
        }
        if (method == "POST" && path.StartsWith("/api/device-agent/commands/", StringComparison.Ordinal)
            && path.EndsWith("/ack", StringComparison.Ordinal))
        {
            var id = path.Split('/')[^2];
            var command = _commands.FirstOrDefault(candidate => candidate.Id == id);
            command?.Acknowledged = true;
            return Data(new { commandId = id, acknowledged = true });
        }
        if (method == "POST" && path.Contains("/session/", StringComparison.Ordinal)
            && path.EndsWith("/state", StringComparison.Ordinal))
        {
            return await TransitionAsync(request);
        }
        if (method == "GET" && path == "/api/device-agent/storage/manifest")
        {
            var student = _session?.StudentId ?? string.Empty;
            var space = Space(student);
            return Data(new
            {
                manifestId = Guid.NewGuid().ToString(),
                version = space.ConfirmedVersion,
                entries = space.Files.OrderBy(file => file.Key, StringComparer.OrdinalIgnoreCase).Select(file => new
                {
                    path = file.Key,
                    sha256 = file.Value.Sha256,
                    sizeBytes = file.Value.Size,
                    version = file.Value.Version,
                }),
            });
        }
        if (method == "POST" && path == "/api/device-agent/storage/manifest")
        {
            var body = JsonNode.Parse(await request.Content!.ReadAsStringAsync(cancellationToken))!;
            return MergeManifest(body);
        }
        if (method == "POST" && path == "/api/device-agent/storage/manifest/rebase")
        {
            var body = JsonNode.Parse(await request.Content!.ReadAsStringAsync(cancellationToken))!;
            if (!_rebaseAuthorized) return Error(409, "recovery_not_authorized");
            var space = Space(_session!.StudentId);
            if (!space.Manifests.TryGetValue((long)body["version"]!, out var old)) return Error(404, "not_found");
            if (old.Entries.Where(entry => entry.State == "conflict").Any(entry => entry.Resolution != "keep_server"))
                return Error(409, "unresolved_conflicts");
            old.Status = "accepted";
            foreach (var entry in old.Entries) if (entry.Ticket is { } ticket) ticket.Used = true;
            return Data(new { rebased = true, nextVersion = space.Manifests.Keys.Max() + 1 });
        }
        if (method == "POST" && path == "/api/device-agent/storage/ticket")
        {
            var body = JsonNode.Parse(await request.Content!.ReadAsStringAsync(cancellationToken))!;
            return CreateTicket(body);
        }
        if (method == "PUT" && path == "/api/device-agent/storage/file")
        {
            var token = Query(request, "ticket");
            var content = await request.Content!.ReadAsByteArrayAsync(cancellationToken);
            return Upload(token, content);
        }
        if (method == "GET" && path == "/api/device-agent/storage/file")
        {
            return Download(Query(request, "ticket"));
        }
        if (method == "POST" && path == "/api/device-agent/storage/sync")
        {
            var body = JsonNode.Parse(await request.Content!.ReadAsStringAsync(cancellationToken))!;
            var snapshot = new List<SnapshotEntry>();
            foreach (var entry in body["entries"]!.AsArray())
            {
                snapshot.Add(new SnapshotEntry((string) entry!["path"]!,
                    ((string) entry["sha256"]!).ToLowerInvariant(), (long) entry["size"]!));
            }
            return Confirm((long) body["version"]!, snapshot);
        }
        if (method == "POST" && path == "/api/device-agent/events")
        {
            return Data(new { recorded = true });
        }
        return Error(404, "not_found");
    }

    private async Task<HttpResponseMessage> TransferAsync(HttpRequestMessage request, CancellationToken cancellation)
    {
        var parts = request.RequestUri!.AbsolutePath.Split('/');
        FakeTransfer transfer;
        if (parts.Length == 5)
        {
            var input = JsonNode.Parse(await request.Content!.ReadAsStringAsync(cancellation))!;
            var ticket = _tickets.Values.First(t => t.Id == (string)input["ticketId"]! && t.Token == (string)input["token"]!);
            transfer = _transfers.Values.FirstOrDefault(t => t.Ticket == ticket) ?? new FakeTransfer { Ticket = ticket };
            _transfers[transfer.Id] = transfer;
        }
        else
        {
            if (!_transfers.TryGetValue(parts[5], out var stored)) return Error(404, "not_found");
            transfer = stored;
            if (parts.Length > 6 && parts[6] == "complete" && transfer.Result is null)
            {
                using var receipt = Upload(transfer.Ticket.Token, transfer.Bytes ?? []);
                if (!receipt.IsSuccessStatusCode) return Error((int)receipt.StatusCode, "upload_rejected");
                using var body = JsonDocument.Parse(await receipt.Content.ReadAsStringAsync(cancellation));
                transfer.Result = body.RootElement.GetProperty("data").Deserialize<ApiUploadResult>(new JsonSerializerOptions(JsonSerializerDefaults.Web));
            }
        }
        return Data(new
        {
            id = transfer.Id, ticketId = transfer.Ticket.Id, direction = transfer.Ticket.Purpose,
            status = transfer.Result is null ? "pending" : "confirmed", mode = "single",
            expiresAt = DateTimeOffset.UtcNow.AddHours(24).ToString("O"), expectedSize = transfer.Ticket.ExpectedSize,
            expectedSha256 = transfer.Ticket.ExpectedSha256, partSize = (long?)null, totalParts = (int?)null,
            request = new { url = "http://127.0.0.1/test-objects/" + transfer.Id, method = transfer.Ticket.Purpose == "upload" ? "PUT" : "GET", headers = new Dictionary<string,string>(), expiresAt = DateTimeOffset.UtcNow.AddMinutes(5).ToString("O") },
            completedParts = Array.Empty<object>(), result = transfer.Result,
        });
    }

    private async Task<HttpResponseMessage> TransitionAsync(HttpRequestMessage request)
    {
        var id = request.RequestUri!.AbsolutePath.Split('/')[^2];
        var body = JsonNode.Parse(await request.Content!.ReadAsStringAsync())!;
        var status = (string) body["status"]!;
        if (_session is null || _session.Id != id)
        {
            return Error(404, "not_found");
        }
        if (!SessionStateMachine.Allows(_session.Status, status))
        {
            return Error(409, "invalid_transition");
        }
        if (status == "ENDING" && _session.DesiredStatus != "ENDING")
        {
            return Error(409, "end_not_requested");
        }
        _session.Status = status;
        if (status is "CLOSED" or "FAILED")
        {
            _session.DesiredStatus = status;
        }
        Transitions.Add(status);
        return Data(new { sessionId = _session.Id, deviceId = DeviceId, status });
    }

    private object SessionDto() => new
    {
        id = _session!.Id,
        studentId = _session.StudentId,
        status = _session.Status,
        desiredStatus = _session.DesiredStatus,
        startedAt = DateTimeOffset.UtcNow.AddMinutes(-1).ToString("O"),
    };

    private HttpResponseMessage MergeManifest(JsonNode body)
    {
        var version = (long) body["version"]!;
        var student = _session?.StudentId;
        if (student is null)
        {
            return Error(404, "not_found");
        }
        var space = Space(student);
        var entries = new List<FakeEntry>();
        foreach (var entry in body["entries"]!.AsArray())
        {
            entries.Add(new FakeEntry
            {
                Path = (string) entry!["path"]!,
                Operation = (string) entry["op"]!,
                Sha256 = entry["sha256"] is null ? null : (string) entry["sha256"]!,
                Size = entry["size"] is null ? 0 : (long) entry["size"]!,
                BaseSha256 = entry["baseSha256"] is null ? null : (string) entry["baseSha256"]!,
            });
        }
        if (space.Manifests.TryGetValue(version, out var existing))
        {
            if (!ExistingEntriesMatch(existing, entries))
            {
                return Error(409, "manifest_version_conflict");
            }
            if (existing.Status is "confirmed" or "accepted")
            {
                return Data(MergeDto(existing, space, [], [], []));
            }
            var retryUploads = PrepareEntries(existing, space);
            return Data(MergeDto(existing, space, retryUploads, Conflicts(existing), UpToDate(existing)));
        }
        if (version <= space.ConfirmedVersion)
        {
            return Error(409, "stale_version");
        }
        if (space.Manifests.Values.Any(manifest => manifest.Status == "pending"))
        {
            return Error(409, "sync_in_progress");
        }
        _manifestSequence = Math.Max(_manifestSequence, version);
        var manifest = new FakeManifest { Version = version, Entries = entries };
        space.Manifests[version] = manifest;
        var uploads = PrepareEntries(manifest, space);
        return Data(MergeDto(manifest, space, uploads, Conflicts(manifest), UpToDate(manifest)));
    }

    private List<FakeTicket> PrepareEntries(FakeManifest manifest, StudentSpace space)
    {
        var uploads = new List<FakeTicket>();
        foreach (var entry in manifest.Entries)
        {
            if (entry.State is "conflict" or "skipped" or "verified")
            {
                if (entry.State == "verified" && entry.Operation is "add" or "update"
                    && space.Files.TryGetValue(entry.Path, out var current)
                    && current.Sha256 == entry.Sha256 && current.Size == entry.Size)
                {
                    continue;
                }
                if (entry.State is "conflict" or "skipped") continue;
            }
            if (entry.Operation is "add" or "update")
            {
                if (!space.Files.ContainsKey(entry.Path) && space.Deleted.Contains(entry.Path))
                {
                    entry.State = "conflict";
                    continue;
                }
                if (space.Files.TryGetValue(entry.Path, out var file)
                    && file.Sha256 == entry.Sha256 && file.Size == entry.Size)
                {
                    entry.State = "verified";
                    continue;
                }
                if (file is not null && entry.BaseSha256 != file.Sha256)
                {
                    entry.State = "conflict";
                    continue;
                }
                var ticket = entry.Ticket ?? new FakeTicket
                {
                    Purpose = "upload",
                    StudentId = _session!.StudentId,
                    Path = entry.Path,
                    ExpectedSha256 = entry.Sha256,
                    ExpectedSize = entry.Size,
                };
                _tickets[ticket.Token] = ticket;
                entry.Ticket = ticket;
                entry.State = "expected";
                uploads.Add(ticket);
            }
            else
            {
                if (!space.Files.TryGetValue(entry.Path, out var file))
                {
                    entry.State = "skipped";
                    continue;
                }
                if (entry.BaseSha256 != file.Sha256)
                {
                    entry.State = "conflict";
                    continue;
                }
                entry.State = "deletion";
            }
        }
        return uploads;
    }

    private static bool ExistingEntriesMatch(FakeManifest manifest, List<FakeEntry> entries)
    {
        if (manifest.Entries.Count != entries.Count) return false;
        for (var index = 0; index < entries.Count; index++)
        {
            var left = manifest.Entries[index];
            var right = entries[index];
            if (left.Path != right.Path || left.Operation != right.Operation
                || left.Sha256 != right.Sha256 || left.Size != right.Size
                || left.BaseSha256 != right.BaseSha256)
            {
                return false;
            }
        }
        return true;
    }

    private static object MergeDto(FakeManifest manifest, StudentSpace space,
        List<FakeTicket> uploads, List<FakeEntry> conflicts, List<FakeEntry> upToDate) => new
    {
        manifestId = manifest.Id,
        version = manifest.Version,
        uploads = uploads.Select(ticket => new
        {
            path = ticket.Path,
            ticketId = ticket.Id,
            token = ticket.Token,
            expectedSize = ticket.ExpectedSize,
            expectedSha256 = ticket.ExpectedSha256,
        }),
        conflicts = conflicts.Select(entry => new
        {
            path = entry.Path,
            serverSha256 = space.Files.TryGetValue(entry.Path, out var file) ? file.Sha256 : new string('0', 64),
            serverVersion = space.Files.TryGetValue(entry.Path, out var versioned) ? versioned.Version : 0,
            deviceSha256 = entry.Sha256 ?? string.Empty,
            resolution = entry.Resolution,
            serverDeleted = !space.Files.ContainsKey(entry.Path),
        }),
        upToDate = upToDate.Select(entry => new { path = entry.Path }),
        confirmed = false,
        acceptedAt = DateTimeOffset.UtcNow.ToString("O"),
    };

    private static List<FakeEntry> Conflicts(FakeManifest manifest) =>
        manifest.Entries.Where(entry => entry.State == "conflict").ToList();

    private static List<FakeEntry> UpToDate(FakeManifest manifest) =>
        manifest.Entries.Where(entry => entry.State is "verified" or "skipped" or "deletion").ToList();

    private HttpResponseMessage CreateTicket(JsonNode body)
    {
        var student = _session?.StudentId;
        if (student is null || (string?) body["purpose"] != "download")
        {
            return Error(422, "invalid_input");
        }
        var path = (string) body["path"]!;
        var expectedSha = (string?) body["expectedSha256"];
        var expectedSize = (long?) body["expectedSize"];
        var space = Space(student);
        if (!space.Files.TryGetValue(path, out var file))
        {
            return Error(404, "not_found");
        }
        if (expectedSha is not null && (file.Sha256 != expectedSha || file.Size != expectedSize))
        {
            return Error(409, "ticket_stale");
        }
        var ticket = new FakeTicket
        {
            Purpose = "download",
            StudentId = student,
            Path = path,
            Snapshot = file.Content,
            SnapshotSha256 = file.Sha256,
            ExpectedSha256 = expectedSha ?? file.Sha256,
            ExpectedSize = expectedSize ?? file.Size,
        };
        _tickets[ticket.Token] = ticket;
        return Data(new
        {
            id = ticket.Id,
            token = ticket.Token,
            path,
            purpose = "download",
            expectedSha256 = ticket.ExpectedSha256,
            expectedSize = ticket.ExpectedSize,
            expiresAt = ticket.ExpiresAt.ToString("O"),
        });
    }

    private HttpResponseMessage Upload(string token, byte[] content)
    {
        if (!_tickets.TryGetValue(token, out var ticket) || ticket.Purpose != "upload" || ticket.Used
            || ticket.ExpiresAt <= DateTimeOffset.UtcNow)
        {
            return Error(404, "not_found");
        }
        var sha = Hash(content);
        if (ticket.ExpectedSha256 is not null
            && (ticket.ExpectedSha256 != sha || ticket.ExpectedSize != content.LongLength))
        {
            return Error(422, "checksum_mismatch");
        }
        ticket.Used = true;
        var space = Space(ticket.StudentId);
        var version = space.Files.TryGetValue(ticket.Path, out var existing) ? existing.Version + 1 : 1;
        space.Files[ticket.Path] = new FakeFile(content, sha, content.LongLength, version);
        foreach (var manifest in space.Manifests.Values.Where(item => item.Status == "pending"))
        {
            var entry = manifest.Entries.FirstOrDefault(candidate => candidate.Path == ticket.Path);
            if (entry is not null && entry.Ticket?.Token == ticket.Token)
            {
                entry.State = "verified";
            }
        }
        return Data(new { path = ticket.Path, version, sha256 = sha, size = content.LongLength });
    }

    private HttpResponseMessage Download(string token)
    {
        if (!_tickets.TryGetValue(token, out var ticket) || ticket.Purpose != "download" || ticket.Used
            || ticket.ExpiresAt <= DateTimeOffset.UtcNow || ticket.Snapshot is null)
        {
            return Error(404, "not_found");
        }
        ticket.Used = true;
        var response = new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new ByteArrayContent(ticket.Snapshot),
        };
        response.Content.Headers.ContentType = new System.Net.Http.Headers.MediaTypeHeaderValue("application/octet-stream");
        return response;
    }

    private HttpResponseMessage Confirm(long version, List<SnapshotEntry> snapshot)
    {
        var student = _session?.StudentId;
        if (student is null)
        {
            return Error(404, "not_found");
        }
        if (FailConfirmCount > 0)
        {
            FailConfirmCount--;
            return Error(409, "write_not_confirmed");
        }
        var space = Space(student);
        if (snapshot.Select(entry => entry.Path).Distinct(StringComparer.OrdinalIgnoreCase).Count() != snapshot.Count)
        {
            return Error(422, "invalid_input");
        }
        var snapshotHash = SnapshotHash(snapshot);
        var live = space.Files.ToDictionary(file => file.Key,
            file => new SnapshotEntry(file.Key, file.Value.Sha256, file.Value.Size),
            StringComparer.OrdinalIgnoreCase);
        var byPath = snapshot.ToDictionary(entry => entry.Path, StringComparer.OrdinalIgnoreCase);

        FakeManifest? pending = null;
        if (version > space.ConfirmedVersion)
        {
            if (!space.Manifests.TryGetValue(version, out pending) || pending.Status != "pending")
            {
                return Error(409, "manifest_not_submitted");
            }
        }
        var deletePaths = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        if (pending is not null)
        {
            foreach (var entry in pending.Entries)
            {
                if (entry.State == "conflict")
                {
                    return Error(409, "version_conflict");
                }
                if (entry.Operation is "add" or "update")
                {
                    if (!space.Files.TryGetValue(entry.Path, out var file))
                    {
                        return Error(409, "upload_missing");
                    }
                    if (file.Sha256 != entry.Sha256 || file.Size != entry.Size
                        || !byPath.TryGetValue(entry.Path, out var expected)
                        || expected.Sha256 != entry.Sha256 || expected.Size != entry.Size)
                    {
                        return Error(409, "checksum_mismatch");
                    }
                }
                else
                {
                    if (space.Files.TryGetValue(entry.Path, out var file) && entry.BaseSha256 != file.Sha256)
                    {
                        return Error(409, "concurrent_change");
                    }
                    if (byPath.ContainsKey(entry.Path))
                    {
                        return Error(409, "checksum_mismatch");
                    }
                    deletePaths.Add(entry.Path);
                }
            }
        }
        var effective = live.Where(pair => !deletePaths.Contains(pair.Key))
            .Select(pair => pair.Value).ToList();
        if (!SameSnapshot(effective, snapshot))
        {
            return Error(409, "manifest_stale");
        }
        if (pending is not null)
        {
            foreach (var path in deletePaths)
            {
                space.Files.Remove(path);
            }
            pending.Status = "confirmed";
            pending.SnapshotSha256 = snapshotHash;
            space.ConfirmedVersion = version;
            return Data(new { manifestId = pending.Id, version, confirmed = true, verifiedEntries = snapshot.Count });
        }
        if (space.ConfirmedVersion > 0
            && space.Manifests.TryGetValue(space.ConfirmedVersion, out var latest))
        {
            if (latest.SnapshotSha256 == snapshotHash)
            {
                return Data(new { manifestId = latest.Id, version = latest.Version, confirmed = true, verifiedEntries = snapshot.Count });
            }
            if (version <= space.ConfirmedVersion)
            {
                if (space.Manifests.Values.Any(manifest => manifest.Status == "pending"))
                {
                    return Error(409, "sync_in_progress");
                }
                var checkpoint = new FakeManifest
                {
                    Version = space.ConfirmedVersion + 1,
                    Entries = new List<FakeEntry>(),
                    Status = "confirmed",
                    SnapshotSha256 = snapshotHash,
                };
                space.Manifests[checkpoint.Version] = checkpoint;
                space.ConfirmedVersion = checkpoint.Version;
                return Data(new { manifestId = checkpoint.Id, version = checkpoint.Version, confirmed = true, verifiedEntries = snapshot.Count });
            }
        }
        if (space.ConfirmedVersion == 0 && version <= 0)
        {
            var baseline = new FakeManifest
            {
                Version = 1,
                Entries = new List<FakeEntry>(),
                Status = "confirmed",
                SnapshotSha256 = snapshotHash,
            };
            space.Manifests[1] = baseline;
            space.ConfirmedVersion = 1;
            return Data(new { manifestId = baseline.Id, version = 1, confirmed = true, verifiedEntries = snapshot.Count });
        }
        return Error(409, "stale_version");
    }

    private static bool SameSnapshot(IEnumerable<SnapshotEntry> live, List<SnapshotEntry> snapshot)
    {
        var left = live.OrderBy(entry => entry.Path, StringComparer.OrdinalIgnoreCase).ToArray();
        var right = snapshot.OrderBy(entry => entry.Path, StringComparer.OrdinalIgnoreCase).ToArray();
        if (left.Length != right.Length) return false;
        for (var index = 0; index < left.Length; index++)
        {
            if (!string.Equals(left[index].Path, right[index].Path, StringComparison.OrdinalIgnoreCase)
                || left[index].Sha256 != right[index].Sha256 || left[index].Size != right[index].Size)
            {
                return false;
            }
        }
        return true;
    }

    private static string SnapshotHash(IEnumerable<SnapshotEntry> snapshot) =>
        Hash(Encoding.UTF8.GetBytes(string.Join('\n',
            snapshot.OrderBy(entry => entry.Path, StringComparer.OrdinalIgnoreCase)
                .Select(entry => $"{entry.Path}\0{entry.Sha256}\0{entry.Size}"))));

    private static string Query(HttpRequestMessage request, string name)
    {
        var query = request.RequestUri!.Query.TrimStart('?');
        foreach (var part in query.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var pair = part.Split('=', 2);
            if (pair[0] == name)
            {
                return Uri.UnescapeDataString(pair.Length == 2 ? pair[1] : string.Empty);
            }
        }
        return string.Empty;
    }

    private static string Hash(byte[] content) => ManifestEngine.HashBytes(content);

    private static HttpResponseMessage Data(object data) => new(HttpStatusCode.OK)
    {
        Content = JsonContent(new { data }),
    };

    private static HttpResponseMessage Error(int status, string reason) => new((HttpStatusCode) status)
    {
        Content = JsonContent(new { error = new { code = "validation.failed", meta = new { storageCode = reason } } }),
    };

    private static StringContent JsonContent(object value) =>
        new(JsonSerializer.Serialize(value), Encoding.UTF8, "application/json");
}

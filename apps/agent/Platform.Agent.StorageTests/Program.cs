using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text.Json;
using Platform.Agent;

namespace Platform.Agent.StorageTests;

/// <summary>Independent transport faults exercise the production client and durable journal.</summary>
internal static class Program
{
    private static async Task<int> Main()
    {
        var cases = new (string Name, Func<Task> Body)[]
        {
            ("260 MiB upload uses StreamContent with bounded allocations", LargeUpload),
            ("multipart retries one failed part", MultipartRetry),
            ("multipart resumes persisted parts after client restart", MultipartRestart),
            ("lost completion response replays receipt without retransmission", LostCompletion),
            ("verifying session resumes completion without uploading bytes", VerifyingResume),
            ("download interruption at ten percent preserves Documents", () => DownloadRestart(10)),
            ("download interruption at fifty percent resumes verified chunks", () => DownloadRestart(50)),
            ("download interruption at ninety-nine percent resumes verified chunks", () => DownloadRestart(99)),
            ("incorrect downloaded checksum preserves unique local data", BadChecksum),
            ("wrong Content-Range cannot replace Documents", WrongRange),
            ("server ignoring Range restarts download safely", IgnoredRange),
            ("expired signed URL refreshes metadata", ExpiredObjectUrl),
            ("changed local upload is rejected before metadata request", ChangedLocalFile),
            ("signed object request cannot inject credentials", UnsafeHeaders),
            ("wrong completion receipt cannot confirm local upload", WrongReceipt),
        };
        var failures = 0;
        foreach (var (name, body) in cases)
        {
            try { await body(); Console.WriteLine("PASS " + name); }
            catch (Exception failure)
            {
                failures++;
                // Exceptions may contain transport URIs. Report only the type and our fixed assertion text.
                Console.WriteLine("FAIL " + name + " (" + failure.GetType().Name + ")");
                if (failure is QaFailure) Console.WriteLine(failure.Message);
            }
        }
        Console.WriteLine($"Agent storage tests: {cases.Length - failures}/{cases.Length}");
        return failures == 0 ? 0 : 1;
    }

    private static async Task LargeUpload()
    {
        using var fixture = await Fixture.Create(260L * 1024 * 1024 + 7);
        var handler = fixture.Handler();
        using var api = fixture.Api(handler);
        var before = GC.GetTotalAllocatedBytes(true);
        var receipt = await api.Client.UploadFileAsync(Fixture.DeviceToken, fixture.Upload, fixture.Source, fixture.Journal, default);
        var allocated = GC.GetTotalAllocatedBytes(true) - before;
        Check(receipt.Size == fixture.Size && handler.BytesUploaded == fixture.Size, "Large upload did not transfer every byte.");
        Check(handler.StreamingBodies == 1 && allocated < 64L * 1024 * 1024, "Large upload allocated memory proportional to file size.");
        fixture.AssertNoSecrets();
        Check(File.Exists(fixture.Source), "Upload removed its source document.");
    }

    private static async Task MultipartRetry()
    {
        using var fixture = await Fixture.Create(12 * 1024 * 1024 + 3);
        var handler = fixture.Handler(multipart: true); handler.FailPartOnce = 2;
        using var api = fixture.Api(handler, retries: 2);
        await api.Client.UploadFileAsync(Fixture.DeviceToken, fixture.Upload, fixture.Source, fixture.Journal, default);
        Check(handler.PartAttempts.GetValueOrDefault(1) == 1 && handler.PartAttempts.GetValueOrDefault(2) == 2
            && handler.PartAttempts.GetValueOrDefault(3) == 1, "A failed part caused retransmission of unrelated parts.");
        Check(fixture.Journal.Transfers.Single().Status == "confirmed", "Multipart upload lacks a confirmed receipt.");
        fixture.AssertNoSecrets();
    }

    private static async Task MultipartRestart()
    {
        using var fixture = await Fixture.Create(12 * 1024 * 1024 + 11);
        var handler = fixture.Handler(multipart: true); handler.FailPartOnce = 2;
        using (var first = fixture.Api(handler, retries: 1))
            await Throws<AgentTransportException>(() => first.Client.UploadFileAsync(Fixture.DeviceToken, fixture.Upload, fixture.Source, fixture.Journal, default));
        Check(fixture.Journal.Transfers.Single().Status != "confirmed", "Interrupted upload was marked confirmed.");
        Check(fixture.Journal.Transfers.Single().UploadedParts.ContainsKey(1), "Completed part was not saved durably.");
        fixture.Journal = StateJournal.Load(fixture.Journal.Directory);
        handler.OmitRemotePartHashes = true;
        using (var restarted = fixture.Api(handler))
            await restarted.Client.UploadFileAsync(Fixture.DeviceToken, fixture.Upload, fixture.Source, fixture.Journal, default);
        Check(handler.PartAttempts[1] == 1, "A durable completed part was uploaded again after restart.");
        Check(fixture.Journal.Transfers.Single().Status == "confirmed", "Resumed multipart was not confirmed.");
    }

    private static async Task LostCompletion()
    {
        using var fixture = await Fixture.Create(333);
        var handler = fixture.Handler(); handler.LoseFirstCompletion = true;
        using var api = fixture.Api(handler, retries: 2);
        await api.Client.UploadFileAsync(Fixture.DeviceToken, fixture.Upload, fixture.Source, fixture.Journal, default);
        Check(handler.UploadRequests == 1 && handler.CompleteRequests == 2, "Completion replay uploaded the file again.");
    }

    private static async Task VerifyingResume()
    {
        using var fixture = await Fixture.Create(333);
        var handler = fixture.Handler(); handler.Verifying = true;
        using var api = fixture.Api(handler);
        await api.Client.UploadFileAsync(Fixture.DeviceToken, fixture.Upload, fixture.Source, fixture.Journal, default);
        Check(handler.UploadRequests == 0 && handler.CompleteRequests == 1, "Verifying session retransmitted already received bytes.");
    }

    private static async Task DownloadRestart(int percentage, bool ignoreRange = false, bool wrongRange = false)
    {
        using var fixture = await Fixture.Create(24 * 1024 * 1024 + 23);
        var target = Path.Combine(fixture.Documents, "work.bin");
        await File.WriteAllTextAsync(target, "unique local recovery work");
        var handler = fixture.Handler(download: true);
        handler.InterruptAt = fixture.Size * percentage / 100;
        using (var first = fixture.Api(handler, retries: 1))
            await Throws<AgentTransportException>(() => first.Client.DownloadFileAsync(Fixture.DeviceToken, fixture.Download,
                fixture.Documents, "work.bin", fixture.Journal, default));
        Check(await File.ReadAllTextAsync(target) == "unique local recovery work", "Interrupted download replaced unique local work.");
        Check(!Directory.EnumerateFiles(fixture.Documents, "*.partial", SearchOption.AllDirectories).Any(), "A partial download appeared inside Documents.");
        fixture.Journal = StateJournal.Load(fixture.Journal.Directory);
        var expectedOffset = fixture.Journal.Transfers.Single().DownloadChunks.Sum(chunk => (long)chunk.SizeBytes);
        handler.IgnoreRange = ignoreRange; handler.WrongRange = wrongRange;
        using var restarted = fixture.Api(handler);
        if (wrongRange)
        {
            await Throws<AgentIntegrityException>(() => restarted.Client.DownloadFileAsync(Fixture.DeviceToken, fixture.Download,
                fixture.Documents, "work.bin", fixture.Journal, default));
            Check(await File.ReadAllTextAsync(target) == "unique local recovery work", "Invalid Content-Range replaced the document.");
            return;
        }
        await restarted.Client.DownloadFileAsync(Fixture.DeviceToken, fixture.Download, fixture.Documents, "work.bin", fixture.Journal, default);
        Check(handler.DownloadOffsets.Last() == expectedOffset, "Restart did not request the last verified chunk boundary.");
        Check(await Sha(target) == fixture.Digest, "Resumed download content is incorrect.");
        var operation = fixture.Journal.Transfers.Single();
        Check(operation.RecoveryCopyPath is not null, "The unique overwritten document lacks a recovery receipt.");
        var recovery = ManifestEngine.ResolvePath(fixture.Documents, operation.RecoveryCopyPath!);
        Check(File.Exists(recovery), "The unique overwritten document lacks a recovery copy.");
        Check(await File.ReadAllTextAsync(recovery) == "unique local recovery work", "Recovery copy was altered.");
        Check(operation.Status == "confirmed", "Verified download was not marked confirmed.");
        fixture.AssertNoSecrets();
    }

    private static Task WrongRange() => DownloadRestart(50, wrongRange: true);
    private static Task IgnoredRange() => DownloadRestart(50, ignoreRange: true);

    private static async Task BadChecksum()
    {
        using var fixture = await Fixture.Create(12345);
        var handler = fixture.Handler(download: true); handler.CorruptDownload = true;
        var target = Path.Combine(fixture.Documents, "work.bin"); await File.WriteAllTextAsync(target, "unique local work");
        using var api = fixture.Api(handler);
        await Throws<AgentIntegrityException>(() => api.Client.DownloadFileAsync(Fixture.DeviceToken, fixture.Download,
            fixture.Documents, "work.bin", fixture.Journal, default));
        Check(await File.ReadAllTextAsync(target) == "unique local work", "Checksum failure replaced unique local data.");
        Check(fixture.Journal.Transfers.Single().Status != "confirmed", "Checksum failure confirmed the download.");
    }

    private static async Task ExpiredObjectUrl()
    {
        using var fixture = await Fixture.Create(12345);
        var handler = fixture.Handler(download: true); handler.ExpireFirstObject = true;
        using var api = fixture.Api(handler, retries: 2);
        await api.Client.DownloadFileAsync(Fixture.DeviceToken, fixture.Download, fixture.Documents, "work.bin", fixture.Journal, default);
        Check(handler.RefreshRequests >= 2 && handler.DownloadOffsets.Count == 2, "An expired URL was not refreshed through metadata.");
    }

    private static async Task ChangedLocalFile()
    {
        using var fixture = await Fixture.Create(12345);
        await using (var file = new FileStream(fixture.Source, FileMode.Open, FileAccess.Write)) file.WriteByte(73);
        var handler = fixture.Handler(); using var api = fixture.Api(handler);
        await Throws<AgentIntegrityException>(() => api.Client.UploadFileAsync(Fixture.DeviceToken, fixture.Upload, fixture.Source, fixture.Journal, default));
        Check(handler.MetadataRequests == 0 && File.Exists(fixture.Source), "Changed source reached the server or was lost.");
    }

    private static async Task UnsafeHeaders()
    {
        using var fixture = await Fixture.Create(12345);
        var handler = fixture.Handler(); handler.UnsafeHeader = true; using var api = fixture.Api(handler);
        await Throws<AgentIntegrityException>(() => api.Client.UploadFileAsync(Fixture.DeviceToken, fixture.Upload, fixture.Source, fixture.Journal, default));
        Check(handler.UploadRequests == 0, "Unsafe signed headers reached object storage.");
    }

    private static async Task WrongReceipt()
    {
        using var fixture = await Fixture.Create(12345);
        var handler = fixture.Handler(); handler.WrongReceipt = true; using var api = fixture.Api(handler);
        await Throws<AgentIntegrityException>(() => api.Client.UploadFileAsync(Fixture.DeviceToken, fixture.Upload, fixture.Source, fixture.Journal, default));
        Check(fixture.Journal.Transfers.Single().Status != "confirmed", "Wrong receipt confirmed the local upload.");
    }

    internal static void Check(bool condition, string text) { if (!condition) throw new QaFailure(text); }
    private static async Task Throws<T>(Func<Task> action) where T : Exception
    {
        try { await action(); }
        catch (T) { return; }
        throw new QaFailure("Expected " + typeof(T).Name + ".");
    }
    internal static async Task<string> Sha(string file)
    {
        await using var stream = File.OpenRead(file);
        return Convert.ToHexStringLower(await SHA256.HashDataAsync(stream));
    }
    private sealed class QaFailure(string message) : Exception(message);

    private sealed class Fixture : IDisposable
    {
        public const string DeviceToken = "qa-device-private-token";
        public const string TicketToken = "qa-ticket-private-token";
        public const string TicketId = "01961be3-2d44-7000-8000-000000000005";
        public const string TransferId = "01961be3-2d44-7000-8000-000000000006";
        public required string Root { get; init; }
        public required string Source { get; init; }
        public required string Documents { get; init; }
        public required long Size { get; init; }
        public required string Digest { get; init; }
        public required StateJournal Journal { get; set; }
        public UploadTicket Upload => new("work.bin", TicketId, TicketToken, Size, Digest);
        public DownloadTicket Download => new("work.bin", TicketToken, Digest, Size, TicketId);

        public static async Task<Fixture> Create(long size)
        {
            var root = Path.Combine(Path.GetTempPath(), "platform-agent-storage-qa-" + Guid.NewGuid().ToString("N"));
            var source = Path.Combine(root, "source.bin"); var documents = Path.Combine(root, "profile", "Documents");
            Directory.CreateDirectory(documents);
            await using (var output = File.Create(source))
            {
                var buffer = new byte[64 * 1024];
                for (var position = 0L; position < size; position += buffer.Length)
                    await output.WriteAsync(buffer.AsMemory(0, (int)Math.Min(buffer.Length, size - position)));
            }
            var journal = new StateJournal { Directory = Path.Combine(root, "journal"), SessionId = "qa-session" };
            journal.Save();
            return new Fixture { Root = root, Source = source, Documents = documents, Size = size, Digest = await Sha(source), Journal = journal };
        }

        public ObjectServer Handler(bool multipart = false, bool download = false) => new(this, multipart, download);
        public ClientLease Api(ObjectServer handler, int retries = 1)
        {
            var http = new HttpClient(handler, disposeHandler: false) { BaseAddress = new Uri("http://127.0.0.1:8089/") };
            return new ClientLease(http, new AgentApiClient(http, new AgentTransferOptions
            {
                Concurrency = 1, RetryAttempts = retries, RetryBaseMilliseconds = 0, RequestTimeoutSeconds = 30,
            }));
        }
        public void AssertNoSecrets()
        {
            var text = File.ReadAllText(Path.Combine(Journal.Directory, StateJournal.FileName));
            Check(!text.Contains(DeviceToken) && !text.Contains(TicketToken) && !text.Contains("signed-request"), "A signed URL or transfer credential was persisted.");
        }
        public void Dispose()
        {
            var full = Path.GetFullPath(Root); var temporary = Path.GetFullPath(Path.GetTempPath());
            if (!full.StartsWith(temporary, StringComparison.OrdinalIgnoreCase)
                || !Path.GetFileName(full).StartsWith("platform-agent-storage-qa-", StringComparison.Ordinal))
                throw new IOException("Unexpected test cleanup directory.");
            Directory.Delete(full, recursive: true);
        }
    }

    private sealed record ClientLease(HttpClient Http, AgentApiClient Client) : IDisposable
    {
        public void Dispose() => Http.Dispose();
    }

    private sealed class ObjectServer(Fixture fixture, bool multipart, bool download) : HttpMessageHandler
    {
        private const long PartSize = 5 * 1024 * 1024;
        private readonly Dictionary<int, (string Sha, long Size)> _parts = new();
        private bool _confirmed;
        public int? FailPartOnce { get; set; }
        public bool OmitRemotePartHashes { get; set; }
        public bool LoseFirstCompletion { get; set; }
        public bool Verifying { get; set; }
        public long? InterruptAt { get; set; }
        public bool CorruptDownload { get; set; }
        public bool WrongRange { get; set; }
        public bool IgnoreRange { get; set; }
        public bool ExpireFirstObject { get; set; }
        public bool UnsafeHeader { get; set; }
        public bool WrongReceipt { get; set; }
        public int MetadataRequests { get; private set; }
        public int UploadRequests { get; private set; }
        public int StreamingBodies { get; private set; }
        public long BytesUploaded { get; private set; }
        public int CompleteRequests { get; private set; }
        public int RefreshRequests { get; private set; }
        public Dictionary<int, int> PartAttempts { get; } = new();
        public List<long> DownloadOffsets { get; } = new();

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var uri = request.RequestUri!;
            if (uri.Port == 8089)
            {
                MetadataRequests++;
                Check(request.Headers.Authorization?.Parameter == Fixture.DeviceToken, "Metadata request lost device authentication.");
                if (uri.AbsolutePath.EndsWith("/parts", StringComparison.Ordinal))
                {
                    using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync(cancellationToken));
                    var part = body.RootElement.GetProperty("parts")[0];
                    var number = part.GetProperty("partNumber").GetInt32();
                    return Json(new { items = new[] { new SignedObjectPart(number, Signed("PUT", number.ToString())) } });
                }
                if (uri.AbsolutePath.EndsWith("/complete", StringComparison.Ordinal))
                {
                    CompleteRequests++; _confirmed = true;
                    if (LoseFirstCompletion && CompleteRequests == 1) return new HttpResponseMessage(HttpStatusCode.ServiceUnavailable);
                }
                if (uri.AbsolutePath.EndsWith("/refresh", StringComparison.Ordinal)) RefreshRequests++;
                return Json(Transfer());
            }
            Check(uri.Port == 9009, "Unexpected object destination.");
            Check(request.Headers.Authorization is null && !request.Headers.Contains("Cookie"), "Device credentials leaked to object storage.");
            if (request.Method == HttpMethod.Put)
            {
                UploadRequests++;
                Check(request.Content is StreamContent, "Object upload did not use StreamContent."); StreamingBodies++;
                var part = multipart ? int.Parse(uri.AbsolutePath.Split('/').Last()) : 1;
                PartAttempts[part] = PartAttempts.GetValueOrDefault(part) + 1;
                if (FailPartOnce == part) { FailPartOnce = null; return new HttpResponseMessage(HttpStatusCode.ServiceUnavailable); }
                await using var source = await request.Content!.ReadAsStreamAsync(cancellationToken);
                var buffer = new byte[64 * 1024]; var length = 0L;
                using var hash = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
                for (int count; (count = await source.ReadAsync(buffer, cancellationToken)) > 0;)
                {
                    length += count; hash.AppendData(buffer, 0, count);
                }
                Check(length == request.Content.Headers.ContentLength, "Signed part length and body differ.");
                _parts[part] = (Convert.ToHexStringLower(hash.GetHashAndReset()), length); BytesUploaded += length;
                return new HttpResponseMessage(HttpStatusCode.OK);
            }
            var offset = request.Headers.Range?.Ranges.Single().From ?? 0;
            DownloadOffsets.Add(offset);
            if (ExpireFirstObject) { ExpireFirstObject = false; return new HttpResponseMessage(HttpStatusCode.Forbidden); }
            var actualOffset = IgnoreRange ? 0 : offset;
            var stream = new FaultingStream(fixture.Source, actualOffset, InterruptAt, CorruptDownload);
            InterruptAt = null;
            var response = new HttpResponseMessage(actualOffset > 0 ? HttpStatusCode.PartialContent : HttpStatusCode.OK)
            {
                Content = new StreamContent(stream),
            };
            response.Content.Headers.ContentLength = fixture.Size - actualOffset;
            if (actualOffset > 0)
                response.Content.Headers.ContentRange = new ContentRangeHeaderValue(WrongRange ? actualOffset + 1 : actualOffset, fixture.Size - 1, fixture.Size);
            return response;
        }

        private ObjectTransfer Transfer()
        {
            var status = _confirmed ? "confirmed" : Verifying ? "verifying" : "uploading";
            var completed = _parts.Select(pair => new CompletedObjectPart(pair.Key, pair.Value.Size, "part-etag-" + pair.Key,
                OmitRemotePartHashes ? null : pair.Value.Sha)).ToArray();
            return new ObjectTransfer(Fixture.TransferId, Fixture.TicketId, download ? "download" : "upload", status,
                multipart ? "multipart" : "single", DateTimeOffset.UtcNow.AddHours(1), fixture.Size, fixture.Digest,
                multipart ? PartSize : null, multipart ? (int)((fixture.Size + PartSize - 1) / PartSize) : null,
                multipart ? null : Signed(download ? "GET" : "PUT", "file"), completed,
                _confirmed ? new ApiUploadResult(WrongReceipt ? "other.bin" : "work.bin", 1, fixture.Digest, fixture.Size) : null);
        }
        private SignedObjectRequest Signed(string method, string path) => new("http://127.0.0.1:9009/signed-request/" + path,
            method, UnsafeHeader ? new Dictionary<string, string> { ["Authorization"] = "must-not-send" } : new(), DateTimeOffset.UtcNow.AddMinutes(1));
        private static HttpResponseMessage Json(object value) => new(HttpStatusCode.OK)
        {
            Content = JsonContent.Create(new { status = "success", data = value }, options: new JsonSerializerOptions(JsonSerializerDefaults.Web)),
        };
    }

    private sealed class FaultingStream : Stream
    {
        private readonly FileStream _source;
        private readonly long? _interruptAt;
        private readonly bool _corrupt;
        public FaultingStream(string file, long offset, long? interruptAt, bool corrupt)
        {
            _source = File.OpenRead(file); _source.Position = offset; _interruptAt = interruptAt; _corrupt = corrupt;
        }
        public override bool CanRead => true;
        public override bool CanSeek => false;
        public override bool CanWrite => false;
        public override long Length => _source.Length;
        public override long Position { get => _source.Position; set => throw new NotSupportedException(); }
        public override async ValueTask<int> ReadAsync(Memory<byte> buffer, CancellationToken cancellationToken = default)
        {
            if (_interruptAt is { } boundary && _source.Position >= boundary) throw new IOException("Injected isolated download interruption.");
            var length = _interruptAt is { } limit ? (int)Math.Min(buffer.Length, limit - _source.Position) : buffer.Length;
            var read = await _source.ReadAsync(buffer[..length], cancellationToken);
            if (_corrupt && read > 0) buffer.Span[0] ^= 0x5A;
            return read;
        }
        public override int Read(byte[] buffer, int offset, int count) => ReadAsync(buffer.AsMemory(offset, count)).AsTask().GetAwaiter().GetResult();
        public override void Flush() => throw new NotSupportedException();
        public override long Seek(long offset, SeekOrigin origin) => throw new NotSupportedException();
        public override void SetLength(long value) => throw new NotSupportedException();
        public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();
        protected override void Dispose(bool disposing) { if (disposing) _source.Dispose(); base.Dispose(disposing); }
        public override ValueTask DisposeAsync() => _source.DisposeAsync();
    }
}

using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace Platform.Agent;

public sealed record SignedObjectRequest(string Url, string Method, Dictionary<string, string> Headers, DateTimeOffset ExpiresAt);
public sealed record CompletedObjectPart(int PartNumber, long SizeBytes, string Etag, string? Sha256 = null);
public sealed record ObjectTransfer(string Id, string TicketId, string Direction, string Status, string Mode,
    DateTimeOffset ExpiresAt, long ExpectedSize, string ExpectedSha256, long? PartSize, int? TotalParts,
    SignedObjectRequest? Request, CompletedObjectPart[] CompletedParts, ApiUploadResult? Result);
public sealed record SignedObjectPart(int PartNumber, SignedObjectRequest Request);

internal sealed class ObjectTransferException(int status) : Exception("Object storage rejected the transfer.")
{
    public int Status { get; } = status;
}

public sealed partial class AgentApiClient
{
    private readonly AgentTransferOptions _transferOptions;
    private readonly TransferBandwidth _bandwidth;

    /// <summary>Holds a read-sharing lock and re-hashes before confirmation; never buffers the whole file.</summary>
    public async Task<ApiUploadResult> UploadFileAsync(string token, UploadTicket ticket, string file,
        StateJournal journal, CancellationToken cancellation)
    {
        if (ticket.ExpectedSha256 is null) throw new AgentIntegrityException("Upload ticket is missing its checksum.");
        ValidateExpected(ticket.ExpectedSize, ticket.ExpectedSha256);
        ManagedPaths.RejectReparseAncestors(file);
        if ((File.GetAttributes(file) & FileAttributes.ReparsePoint) != 0) throw new IOException("Upload source is a reparse point.");
        await using var locked = OpenSource(file);
        if (locked.Length != ticket.ExpectedSize || !await MatchesAsync(locked, ticket.ExpectedSha256, cancellation))
            throw new AgentIntegrityException("A local file changed after its manifest was submitted.");
        var operation = Operation(journal, "upload", ticket.Path, ticket.ExpectedSha256, ticket.ExpectedSize, ticket.TicketId);
        var transfer = await TransferAsync(token, ticket.TicketId, ticket.Token, operation, journal, cancellation);
        if (transfer.Status == "confirmed") return CheckReceipt(transfer.Result, ticket);
        if (transfer.Status is "expired" or "aborted") throw new AgentIntegrityException("Upload transfer requires a new authorized ticket.");
        if (transfer.Status != "verifying" && transfer.Mode == "multipart")
        {
            var partSize = transfer.PartSize ?? 0;
            if (partSize < 5L * 1024 * 1024 || partSize > 5L * 1024 * 1024 * 1024)
                throw new AgentIntegrityException("Invalid multipart part size.");
            var total = checked((int)((ticket.ExpectedSize + partSize - 1) / partSize));
            if (total is < 1 or > 10000 || total != transfer.TotalParts)
                throw new AgentIntegrityException("Invalid multipart count.");
            await Parallel.ForEachAsync(Enumerable.Range(1, total), new ParallelOptions
            {
                MaxDegreeOfParallelism = _transferOptions.Concurrency, CancellationToken = cancellation,
            }, async (number, ct) =>
            {
                var offset = (number - 1) * partSize;
                var length = Math.Min(partSize, ticket.ExpectedSize - offset);
                string sha;
                await using (var source = OpenSource(file))
                {
                    source.Position = offset;
                    using var part = new TransferReadStream(source, length);
                    sha = Convert.ToHexStringLower(await SHA256.HashDataAsync(part, ct));
                }
                var remote = transfer.CompletedParts.FirstOrDefault(p => p.PartNumber == number);
                string? remembered;
                lock (journal.TransferGate) operation.UploadedParts.TryGetValue(number, out remembered);
                if (remote is not null && remote.SizeBytes == length
                    && (string.Equals(remote.Sha256, sha, StringComparison.OrdinalIgnoreCase)
                        || string.Equals(remembered, sha, StringComparison.OrdinalIgnoreCase))) return;
                await RetryAsync(async attemptToken =>
                {
                    var data = await TransferMetadataAsync(token, $"{operation.TransferId}/parts", HttpMethod.Post,
                        new { parts = new[] { new { partNumber = number, sha256 = sha, sizeBytes = length } } }, attemptToken);
                    var signed = data.GetProperty("items").Deserialize<SignedObjectPart[]>(WireJson)
                        ?? throw new AgentIntegrityException("Invalid part signature response.");
                    if (signed.Length != 1 || signed[0].PartNumber != number)
                        throw new AgentIntegrityException("Part signature does not match the requested part.");
                    await using var source = OpenSource(file);
                    source.Position = offset;
                    using var body = new TransferReadStream(source, length, _bandwidth);
                    using var request = ObjectRequest(signed[0].Request, "PUT", body, length);
                    using var response = await SendObjectAsync(request, attemptToken);
                    if (!response.IsSuccessStatusCode) throw new ObjectTransferException((int)response.StatusCode);
                }, cancellation);
                Update(journal, operation, () => operation.UploadedParts[number] = sha);
            });
        }
        else if (transfer.Status != "verifying" && transfer.Mode == "single")
        {
            await RetryAsync(async attemptToken =>
            {
                var fresh = await RefreshAsync(token, operation, journal, attemptToken);
                if (fresh.Status == "confirmed") return;
                await using var source = OpenSource(file);
                using var body = new TransferReadStream(source, ticket.ExpectedSize, _bandwidth);
                using var request = ObjectRequest(fresh.Request, "PUT", body, ticket.ExpectedSize);
                using var response = await SendObjectAsync(request, attemptToken);
                if (!response.IsSuccessStatusCode) throw new ObjectTransferException((int)response.StatusCode);
            }, cancellation);
        }
        else if (transfer.Status != "verifying") throw new AgentIntegrityException("Unsupported upload transfer mode.");
        locked.Position = 0;
        if (locked.Length != ticket.ExpectedSize || !await MatchesAsync(locked, ticket.ExpectedSha256, cancellation))
            throw new AgentIntegrityException("Upload source changed during transmission; local documents were preserved.");
        ObjectTransfer? completed = null;
        await RetryAsync(async ct => completed = ParseTransfer(await TransferMetadataAsync(token,
            $"{operation.TransferId}/complete", HttpMethod.Post, new { }, ct), operation), cancellation);
        if (completed?.Status != "confirmed") throw new AgentIntegrityException("The server has not confirmed the uploaded object.");
        var result = CheckReceipt(completed.Result, ticket);
        Update(journal, operation, () => operation.Status = "confirmed");
        return result;
    }

    /// <summary>Checkpoints validated chunks outside Documents, resumes Range and places only a fully verified file.</summary>
    public async Task DownloadFileAsync(string token, DownloadTicket ticket, string root, string relative,
        StateJournal journal, CancellationToken cancellation, bool preserveExisting = true)
    {
        ValidateExpected(ticket.ExpectedSize, ticket.ExpectedSha256);
        if (ticket.TicketId is null) throw new AgentIntegrityException("Download ticket is missing its identifier.");
        var operation = Operation(journal, "download", relative, ticket.ExpectedSha256, ticket.ExpectedSize, null);
        await TransferAsync(token, ticket.TicketId, ticket.Token, operation, journal, cancellation);
        var absoluteRoot = Path.GetFullPath(root);
        var parent = Path.GetDirectoryName(absoluteRoot) ?? throw new IOException("Documents has no parent.");
        var stage = Path.Combine(parent, ".platform-transfer-staging");
        StateDirectorySecurity.ProtectDirectory(stage);
        var partial = Path.Combine(stage, operation.Key + ".partial");
        ManagedPaths.RejectReparseAncestors(partial);
        LegacyInstallation.RestoreDownloadPartial(parent, operation.Key, partial);
        if (File.Exists(partial) && (File.GetAttributes(partial) & FileAttributes.ReparsePoint) != 0)
            throw new IOException("Download partial is a reparse point.");
        var target = ManifestEngine.ResolvePath(root, relative);
        if (ManifestEngine.ContentMatches(target, ticket.ExpectedSha256, ticket.ExpectedSize))
        {
            Update(journal, operation, () => operation.Status = "confirmed");
            return;
        }
        await RetryAsync(async ct =>
        {
            var fresh = await RefreshAsync(token, operation, journal, ct);
            await using var output = new FileStream(partial, FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None,
                AgentTransferOptions.BufferSize, FileOptions.Asynchronous | FileOptions.WriteThrough);
            var offset = await VerifyChunksAsync(output, operation, ct);
            output.SetLength(offset); // An uncheckpointed tail from a process crash is never trusted.
            if (offset == ticket.ExpectedSize)
            {
                output.Position = 0;
                if (await MatchesAsync(output, ticket.ExpectedSha256, ct)) return;
                throw new AgentIntegrityException("Checkpointed download failed its full checksum.");
            }
            using var request = ObjectRequest(fresh.Request, "GET");
            if (offset > 0) request.Headers.Range = new RangeHeaderValue(offset, null);
            using var response = await SendObjectAsync(request, ct);
            if (response.StatusCode == HttpStatusCode.PartialContent)
            {
                var range = response.Content.Headers.ContentRange;
                if (range?.From != offset || range.Length != ticket.ExpectedSize || range.To is null
                    || range.To < offset || range.To >= ticket.ExpectedSize)
                    throw new AgentIntegrityException("Download Content-Range does not match the pinned version.");
            }
            else if (response.StatusCode == HttpStatusCode.OK)
            {
                if (offset > 0)
                {
                    output.SetLength(0); offset = 0;
                    Update(journal, operation, () => operation.DownloadChunks.Clear());
                }
            }
            else throw new ObjectTransferException((int)response.StatusCode);
            if (response.Content.Headers.ContentLength is { } length && length != ticket.ExpectedSize - offset)
                throw new AgentIntegrityException("Download length does not match the pinned version.");
            output.Position = offset;
            await using var input = await response.Content.ReadAsStreamAsync(ct);
            var buffer = new byte[AgentTransferOptions.BufferSize];
            using var chunkHash = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
            var chunkOffset = offset; var chunkSize = 0;
            while (true)
            {
                var read = await input.ReadAsync(buffer.AsMemory(0, Math.Min(buffer.Length,
                    AgentTransferOptions.CheckpointBytes - chunkSize)), ct);
                if (read == 0) break;
                if (output.Position + read > ticket.ExpectedSize) throw new AgentIntegrityException("Download exceeds its expected size.");
                await _bandwidth.WaitAsync(read, ct);
                await output.WriteAsync(buffer.AsMemory(0, read), ct);
                chunkHash.AppendData(buffer, 0, read); chunkSize += read;
                if (chunkSize == AgentTransferOptions.CheckpointBytes)
                {
                    output.Flush(true);
                    var saved = new DownloadChunk(chunkOffset, chunkSize, Convert.ToHexStringLower(chunkHash.GetHashAndReset()));
                    Update(journal, operation, () => operation.DownloadChunks.Add(saved));
                    chunkOffset += chunkSize; chunkSize = 0;
                }
            }
            output.Flush(true);
            if (output.Length != ticket.ExpectedSize) throw new ObjectTransferException(502);
            if (chunkSize > 0)
            {
                var saved = new DownloadChunk(chunkOffset, chunkSize, Convert.ToHexStringLower(chunkHash.GetHashAndReset()));
                Update(journal, operation, () => operation.DownloadChunks.Add(saved));
            }
            output.Position = 0;
            if (!await MatchesAsync(output, ticket.ExpectedSha256, ct))
                throw new AgentIntegrityException("Downloaded file failed checksum verification; Documents were not replaced.");
        }, cancellation);
        PlaceVerified(root, relative, partial, ticket.ExpectedSha256, ticket.ExpectedSize, operation, journal, preserveExisting);
        Update(journal, operation, () => operation.Status = "confirmed");
    }

    private async Task<ObjectTransfer> TransferAsync(string token, string ticketId, string ticketToken,
        TransferOperation operation, StateJournal journal, CancellationToken cancellation)
    {
        ObjectTransfer? transfer = null;
        await RetryAsync(async ct =>
        {
            var data = operation.TransferId is null
                ? await TransferMetadataAsync(token, string.Empty, HttpMethod.Post, new { ticketId, token = ticketToken }, ct)
                : await TransferMetadataAsync(token, operation.TransferId, HttpMethod.Get, null, ct);
            transfer = ParseTransfer(data, operation);
            Update(journal, operation, () => { operation.TransferId = transfer.Id; operation.Status = transfer.Status; });
        }, cancellation);
        return transfer!;
    }

    private async Task<ObjectTransfer> RefreshAsync(string token, TransferOperation operation, StateJournal journal, CancellationToken ct)
    {
        var value = ParseTransfer(await TransferMetadataAsync(token, $"{operation.TransferId}/refresh", HttpMethod.Post, new { }, ct), operation);
        if (value.Status is "expired" or "aborted") throw new AgentIntegrityException("Transfer is no longer resumable; local files were preserved.");
        Update(journal, operation, () => operation.Status = value.Status);
        return value;
    }

    private async Task<JsonElement> TransferMetadataAsync(string token, string suffix, HttpMethod method, object? body, CancellationToken ct)
    {
        if (suffix.Split('/')[0] is { Length: > 0 } id && !Guid.TryParse(id, out _))
            throw new AgentIntegrityException("Invalid transfer identifier.");
        using var request = Request(method, "api/device-agent/storage/transfers" + (suffix.Length == 0 ? "" : "/" + suffix), token);
        if (body is not null) request.Content = JsonContent(body);
        using var response = await SendAsync(request, ct);
        await EnsureAsync(response, ct);
        return await ReadAsync(response, ct);
    }

    private static ObjectTransfer ParseTransfer(JsonElement data, TransferOperation operation)
    {
        var value = data.Deserialize<ObjectTransfer>(WireJson) ?? throw new AgentIntegrityException("Invalid transfer response.");
        if (!Guid.TryParse(value.Id, out _) || value.Direction != operation.Direction
            || value.ExpectedSize != operation.ExpectedSize || !string.Equals(value.ExpectedSha256, operation.ExpectedSha256, StringComparison.OrdinalIgnoreCase)
            || value.CompletedParts is null || value.Status is not ("pending" or "uploading" or "verifying" or "confirmed" or "aborted" or "expired"))
            throw new AgentIntegrityException("Transfer response changed the expected object identity.");
        return value;
    }

    private static ApiUploadResult CheckReceipt(ApiUploadResult? result, UploadTicket ticket)
    {
        if (result is null || result.Path != ticket.Path || result.Version < 1 || result.Size != ticket.ExpectedSize
            || !string.Equals(result.Sha256, ticket.ExpectedSha256, StringComparison.OrdinalIgnoreCase))
            throw new AgentIntegrityException("Server confirmation does not match the uploaded file.");
        return result;
    }

    private void ValidateExpected(long size, string sha)
    {
        if (size < 0 || size > _transferOptions.MaxFileBytes || sha.Length != 64 || !sha.All(Uri.IsHexDigit))
            throw new AgentIntegrityException("Invalid transfer size or checksum.");
    }

    private static TransferOperation Operation(StateJournal journal, string direction, string path, string sha, long size, string? ticket)
    {
        ManifestEngine.ValidateRelative(path);
        var key = Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes($"{journal.SessionId}\n{direction}\n{path}\n{sha}\n{size}\n{ticket}")));
        lock (journal.TransferGate)
        {
            var existing = journal.Transfers.FirstOrDefault(t => t.Key == key);
            if (existing is not null) return existing;
            var operation = new TransferOperation { Key = key, SessionId = journal.SessionId ?? string.Empty,
                Direction = direction, Path = path, ExpectedSha256 = sha, ExpectedSize = size };
            journal.Transfers.Add(operation); journal.Save(); return operation;
        }
    }

    private static void Update(StateJournal journal, TransferOperation operation, Action change)
    {
        lock (journal.TransferGate)
        {
            change(); operation.UpdatedAt = DateTimeOffset.UtcNow; journal.Save();
        }
    }

    private HttpRequestMessage ObjectRequest(SignedObjectRequest? signed, string method, Stream? body = null, long? length = null)
    {
        if (signed is null || signed.Headers is null || signed.Method != method || !Uri.TryCreate(signed.Url, UriKind.Absolute, out var uri)
            || !string.IsNullOrEmpty(uri.UserInfo) || uri.Fragment.Length != 0
            || uri.Scheme != Uri.UriSchemeHttps && !(uri.Scheme == Uri.UriSchemeHttp && uri.IsLoopback
                && _http.BaseAddress?.IsLoopback == true)) throw new AgentIntegrityException("Unsafe signed object request.");
        var request = new HttpRequestMessage(new HttpMethod(method), uri);
        if (body is not null) { request.Content = new StreamContent(body, AgentTransferOptions.BufferSize); request.Content.Headers.ContentLength = length; }
        foreach (var (name, value) in signed.Headers)
        {
            if (name.Equals("Host", StringComparison.OrdinalIgnoreCase)) continue;
            if (name.Equals("Authorization", StringComparison.OrdinalIgnoreCase) || name.Equals("Cookie", StringComparison.OrdinalIgnoreCase)
                || name.Contains('\r') || name.Contains('\n') || value.Contains('\r') || value.Contains('\n'))
                throw new AgentIntegrityException("Unsafe object request header.");
            if (!request.Headers.TryAddWithoutValidation(name, value) && request.Content is not null)
                request.Content.Headers.TryAddWithoutValidation(name, value);
        }
        return request; // A fresh request never receives the device bearer token.
    }

    private async Task<HttpResponseMessage> SendObjectAsync(HttpRequestMessage request, CancellationToken ct)
    {
        try { return await _http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct); }
        catch (HttpRequestException) { throw new ObjectTransferException(503); }
    }

    private async Task RetryAsync(Func<CancellationToken, Task> action, CancellationToken cancellation)
    {
        for (var attempt = 0; ; attempt++)
        {
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellation);
            timeout.CancelAfter(TimeSpan.FromSeconds(_transferOptions.RequestTimeoutSeconds));
            try { await action(timeout.Token); return; }
            catch (Exception failure) when (!cancellation.IsCancellationRequested && Retryable(failure))
            {
                if (attempt + 1 >= _transferOptions.RetryAttempts)
                    throw new AgentTransportException("Storage transfer could not complete; durable state and local documents were preserved.",
                        new IOException("Object transfer retry budget exhausted."));
                var delay = Math.Min(30000, _transferOptions.RetryBaseMilliseconds * (1 << attempt));
                await Task.Delay(delay + Random.Shared.Next(0, Math.Max(1, delay / 3)), cancellation);
            }
        }
    }

    private static bool Retryable(Exception failure) => failure switch
    {
        ObjectTransferException e => e.Status is 403 or 408 or 409 or 425 or 429 || e.Status >= 500,
        AgentApiException e => e.StatusCode is 408 or 429 || e.StatusCode >= 500,
        AgentTransportException or HttpRequestException or OperationCanceledException => true,
        IOException => true,
        _ => false,
    };

    private static FileStream OpenSource(string file) => new(file, FileMode.Open, FileAccess.Read, FileShare.Read,
        AgentTransferOptions.BufferSize, FileOptions.Asynchronous | FileOptions.SequentialScan);

    private static async Task<bool> MatchesAsync(Stream stream, string sha, CancellationToken ct) =>
        string.Equals(Convert.ToHexStringLower(await SHA256.HashDataAsync(stream, ct)), sha, StringComparison.OrdinalIgnoreCase);

    private static async Task<long> VerifyChunksAsync(FileStream stream, TransferOperation operation, CancellationToken ct)
    {
        var offset = 0L;
        foreach (var chunk in operation.DownloadChunks)
        {
            if (chunk.Offset != offset || chunk.SizeBytes is < 1 or > AgentTransferOptions.CheckpointBytes
                || stream.Length < offset + chunk.SizeBytes) throw new AgentIntegrityException("Download checkpoint is inconsistent.");
            stream.Position = offset;
            using var part = new TransferReadStream(stream, chunk.SizeBytes);
            if (!await MatchesAsync(part, chunk.Sha256, ct)) throw new AgentIntegrityException("Download checkpoint checksum changed.");
            offset += chunk.SizeBytes;
        }
        return offset;
    }

    private static void PlaceVerified(string root, string relative, string partial, string sha, long size,
        TransferOperation operation, StateJournal journal, bool preserveExisting)
    {
        if (!ManifestEngine.ContentMatches(partial, sha, size)) throw new AgentIntegrityException("Download partial is not verified.");
        var target = ManifestEngine.ResolvePath(root, relative);
        Directory.CreateDirectory(Path.GetDirectoryName(target)!);
        if (preserveExisting && File.Exists(target) && !ManifestEngine.ContentMatches(target, sha, size))
        {
            var originalSha = ManifestEngine.HashFile(target); var originalSize = new FileInfo(target).Length;
            var extension = Path.GetExtension(relative);
            if (extension.Length > 16 || extension.Any(c => !char.IsAsciiLetterOrDigit(c) && c != '.')) extension = ".bin";
            var recovery = $"Conflicts/Restored/{operation.Key}/{originalSha}{extension}";
            Update(journal, operation, () => operation.RecoveryCopyPath = recovery);
            var copy = ManifestEngine.ResolvePath(root, recovery);
            Directory.CreateDirectory(Path.GetDirectoryName(copy)!);
            if (File.Exists(copy))
            {
                if (!ManifestEngine.ContentMatches(copy, originalSha, originalSize)) throw new AgentIntegrityException("Recovery copy is inconsistent.");
                File.Delete(target); // A verified durable unique copy is already in Documents for the next manifest.
            }
            else File.Move(target, copy);
        }
        // Staging is a sibling of Documents, so the final move remains on the same filesystem.
        File.Move(partial, target, overwrite: true);
    }
}

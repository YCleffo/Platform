using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

namespace KiberOne.Agent;

public sealed record ApiEnrollment(string DeviceId, string CredentialId, string Credential);
public sealed record ApiCommand(string Id, string Kind, JsonElement Payload, string ExpiresAt);
public sealed record ApiSession(string Id, string StudentId, string Status, string? DesiredStatus, string StartedAt);
public sealed record ApiStateReport(string SessionId, string Status);
public sealed record ApiManifestMerge(string ManifestId, long Version, UploadTicket[] Uploads,
    ConflictEntry[] Conflicts, string[] UpToDate, bool Confirmed, string AcceptedAt);
public sealed record UploadTicket(string Path, string TicketId, string Token, long ExpectedSize, string? ExpectedSha256);
public sealed record DownloadTicket(string Path, string Token, string ExpectedSha256, long ExpectedSize);
public sealed record ConflictEntry(string Path, string ServerSha256, int ServerVersion, string DeviceSha256);
public sealed record ApiServerManifest(long Version, ServerFileEntry[] Entries);
public sealed record ApiSyncConfirmation(string ManifestId, long Version, bool Confirmed, int VerifiedEntries);
public sealed record ApiUploadResult(string Path, int Version, string Sha256, long Size);

public class AgentTransportException : Exception
{
    public AgentTransportException(string message, Exception inner) : base(message, inner) { }
}

public sealed class AgentApiException : Exception
{
    public AgentApiException(int statusCode, string reason) : base($"Platform rejected the request ({statusCode}, {reason}).")
    {
        StatusCode = statusCode;
        Reason = reason;
    }

    public int StatusCode { get; }
    public string Reason { get; }
}

public sealed class AgentIntegrityException : Exception
{
    public AgentIntegrityException(string message) : base(message) { }
}

public sealed class AgentConflictException : Exception
{
    public AgentConflictException(IReadOnlyList<string> paths)
        : base("The platform reported a concurrent version conflict.") => Paths = paths;

    public IReadOnlyList<string> Paths { get; }
}

/// <summary>HTTPS polling client for the DeviceManagement and Storage endpoints.</summary>
public sealed class AgentApiClient
{
    private readonly HttpClient _http;

    public AgentApiClient(HttpClient http) => _http = http;

    public static HttpClient CreateHttpClient(string baseUrl, bool allowInsecureLocalHttp)
    {
        if (!Uri.TryCreate(baseUrl, UriKind.Absolute, out var uri))
        {
            throw new InvalidOperationException("Agent base URL must be an absolute URI.");
        }
        if (uri.Scheme == Uri.UriSchemeHttp)
        {
            if (!allowInsecureLocalHttp || !uri.IsLoopback)
            {
                throw new InvalidOperationException(
                    "Plain HTTP is allowed only for an explicitly enabled loopback development endpoint.");
            }
        }
        else if (uri.Scheme != Uri.UriSchemeHttps)
        {
            throw new InvalidOperationException("Agent base URL must use HTTPS.");
        }
        var handler = new HttpClientHandler();
        if (allowInsecureLocalHttp && uri.IsLoopback && uri.Scheme == Uri.UriSchemeHttps)
        {
            handler.ServerCertificateCustomValidationCallback = (request, _, _, _) =>
                request.RequestUri?.IsLoopback == true;
        }
        return new HttpClient(handler)
        {
            BaseAddress = new Uri(uri.ToString().TrimEnd('/') + "/"),
            Timeout = TimeSpan.FromSeconds(30),
        };
    }

    private static HttpRequestMessage Request(HttpMethod method, string path, string? token = null)
    {
        var request = new HttpRequestMessage(method, path);
        if (token is not null)
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        }
        return request;
    }

    public async Task<ApiEnrollment> EnrollAsync(string code, CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Post, "api/device-agent/enroll");
        request.Content = JsonContent(new { code });
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var data = await ReadAsync(response, cancellation);
        return new ApiEnrollment(data.GetProperty("deviceId").GetString()!,
            data.GetProperty("credentialId").GetString()!, data.GetProperty("credential").GetString()!);
    }

    public async Task<ApiSession?> HeartbeatAsync(string token, CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Post, "api/device-agent/heartbeat", token);
        request.Content = JsonContent(new { state = new { version = "kiberone-agent/1.0" } });
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var data = await ReadAsync(response, cancellation);
        return data.TryGetProperty("session", out var session) && session.ValueKind == JsonValueKind.Object
            ? ParseSession(session) : null;
    }

    public async Task<ApiCommand[]> PollCommandsAsync(string token, CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Get, "api/device-agent/commands", token);
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var data = await ReadAsync(response, cancellation);
        var items = data.GetProperty("items");
        var commands = new List<ApiCommand>();
        foreach (var item in items.EnumerateArray())
        {
            commands.Add(new ApiCommand(item.GetProperty("id").GetString()!,
                item.GetProperty("kind").GetString()!,
                item.GetProperty("payload").Clone(),
                item.GetProperty("expiresAt").GetString()!));
        }
        return commands.ToArray();
    }

    public async Task AcknowledgeAsync(string token, string commandId, CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Post, $"api/device-agent/commands/{commandId}/ack", token);
        request.Content = JsonContent(new { });
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
    }

    public async Task<ApiStateReport> ReportSessionStateAsync(string token, string sessionId, string status,
        CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Post, $"api/device-agent/session/{sessionId}/state", token);
        request.Content = JsonContent(new { status });
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var data = await ReadAsync(response, cancellation);
        return new ApiStateReport(data.GetProperty("sessionId").GetString()!,
            data.GetProperty("status").GetString()!);
    }

    public async Task<ApiManifestMerge> PostManifestAsync(string token, long version,
        ManifestEntry[] entries, CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Post, "api/device-agent/storage/manifest", token);
        request.Content = JsonContent(new
        {
            version,
            entries = entries.Select(entry => new
            {
                path = entry.Path,
                op = entry.Operation.ToString().ToLowerInvariant(),
                sha256 = entry.Sha256,
                size = entry.Size,
                baseSha256 = entry.BaseSha256,
            }),
        });
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var data = await ReadAsync(response, cancellation);
        return new ApiManifestMerge(data.GetProperty("manifestId").GetString()!,
            data.GetProperty("version").GetInt64(),
            data.GetProperty("uploads").EnumerateArray().Select(upload => new UploadTicket(
                upload.GetProperty("path").GetString()!, upload.GetProperty("ticketId").GetString()!,
                upload.GetProperty("token").GetString()!, upload.GetProperty("expectedSize").GetInt64(),
                upload.TryGetProperty("expectedSha256", out var uploadSha) && uploadSha.ValueKind == JsonValueKind.String
                    ? uploadSha.GetString() : null)).ToArray(),
            data.GetProperty("conflicts").EnumerateArray().Select(conflict => new ConflictEntry(
                conflict.GetProperty("path").GetString()!, conflict.GetProperty("serverSha256").GetString()!,
                conflict.GetProperty("serverVersion").GetInt32(), conflict.GetProperty("deviceSha256").GetString()!)).ToArray(),
            data.TryGetProperty("upToDate", out var upToDate) && upToDate.ValueKind == JsonValueKind.Array
                ? upToDate.EnumerateArray().Select(item => item.GetProperty("path").GetString()!).ToArray()
                : Array.Empty<string>(),
            data.TryGetProperty("confirmed", out var confirmed) && confirmed.ValueKind == JsonValueKind.True,
            data.GetProperty("acceptedAt").GetString()!);
    }

    public async Task<ApiServerManifest> GetManifestAsync(string token, CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Get, "api/device-agent/storage/manifest", token);
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var data = await ReadAsync(response, cancellation);
        var entries = new List<ServerFileEntry>();
        foreach (var entry in data.GetProperty("entries").EnumerateArray())
        {
            entries.Add(new ServerFileEntry(entry.GetProperty("path").GetString()!,
                entry.GetProperty("sha256").GetString()!.ToLowerInvariant(),
                entry.GetProperty("size").GetInt64(), entry.GetProperty("version").GetInt32()));
        }
        return new ApiServerManifest(data.GetProperty("version").GetInt64(), entries.ToArray());
    }

    public async Task<DownloadTicket> TicketForDownloadAsync(string token, string path, string sha256, long size,
        CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Post, "api/device-agent/storage/ticket", token);
        request.Content = JsonContent(new { purpose = "download", path, expectedSha256 = sha256, expectedSize = size });
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var data = await ReadAsync(response, cancellation);
        return new DownloadTicket(path, data.GetProperty("token").GetString()!,
            data.GetProperty("expectedSha256").GetString()!.ToLowerInvariant(),
            data.GetProperty("expectedSize").GetInt64());
    }

    public async Task<ApiUploadResult> UploadAsync(string token, UploadTicket ticket, byte[] content,
        CancellationToken cancellation)
    {
        var sha256 = ManifestEngine.HashBytes(content);
        if (content.LongLength != ticket.ExpectedSize
            || (ticket.ExpectedSha256 is not null
                && !string.Equals(sha256, ticket.ExpectedSha256, StringComparison.OrdinalIgnoreCase)))
        {
            throw new AgentIntegrityException("Local file does not match the upload ticket.");
        }
        using var request = Request(HttpMethod.Put,
            $"api/device-agent/storage/file?ticket={Uri.EscapeDataString(ticket.Token)}", token);
        request.Headers.Add("X-Content-Sha256", sha256);
        request.Content = new ByteArrayContent(content);
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var data = await ReadAsync(response, cancellation);
        var result = new ApiUploadResult(data.GetProperty("path").GetString()!,
            data.GetProperty("version").GetInt32(), data.GetProperty("sha256").GetString()!.ToLowerInvariant(),
            data.GetProperty("size").GetInt64());
        if (!string.Equals(result.Sha256, sha256, StringComparison.OrdinalIgnoreCase)
            || result.Size != content.LongLength)
        {
            throw new AgentIntegrityException("The platform accepted a different file checksum.");
        }
        return result;
    }

    public async Task<byte[]> DownloadAsync(string token, DownloadTicket ticket, CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Get,
            $"api/device-agent/storage/file?ticket={Uri.EscapeDataString(ticket.Token)}", token);
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var content = await response.Content.ReadAsByteArrayAsync(cancellation);
        if (content.LongLength != ticket.ExpectedSize
            || !string.Equals(ManifestEngine.HashBytes(content), ticket.ExpectedSha256, StringComparison.OrdinalIgnoreCase))
        {
            throw new AgentIntegrityException("Downloaded file failed checksum verification.");
        }
        return content;
    }

    public async Task<ApiSyncConfirmation> ConfirmSyncAsync(string token, long version, ManifestEntry[] snapshot,
        CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Post, "api/device-agent/storage/sync", token);
        request.Content = JsonContent(new
        {
            version,
            entries = snapshot.Select(entry => new
            {
                path = entry.Path,
                sha256 = entry.Sha256,
                size = entry.Size,
            }),
        });
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
        var data = await ReadAsync(response, cancellation);
        return new ApiSyncConfirmation(data.GetProperty("manifestId").GetString()!,
            data.GetProperty("version").GetInt64(),
            data.TryGetProperty("confirmed", out var confirmed) && confirmed.ValueKind == JsonValueKind.True,
            data.TryGetProperty("verifiedEntries", out var verified) && verified.ValueKind == JsonValueKind.Number
                ? verified.GetInt32() : 0);
    }

    public async Task ReportEventAsync(string token, string kind, object payload, CancellationToken cancellation)
    {
        using var request = Request(HttpMethod.Post, "api/device-agent/events", token);
        request.Content = JsonContent(new { kind, payload });
        using var response = await SendAsync(request, cancellation);
        await EnsureAsync(response, cancellation);
    }

    private async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellation)
    {
        try
        {
            return await _http.SendAsync(request, cancellation);
        }
        catch (OperationCanceledException)
        {
            throw;
        }
        catch (HttpRequestException failure)
        {
            throw new AgentTransportException("Platform request failed because of a network error.", failure);
        }
    }

    private static StringContent JsonContent(object value) =>
        new(JsonSerializer.Serialize(value), Encoding.UTF8, "application/json");

    private static async Task<JsonElement> ReadAsync(HttpResponseMessage response, CancellationToken cancellation)
    {
        var body = await response.Content.ReadAsStringAsync(cancellation);
        using var document = JsonDocument.Parse(body);
        var root = document.RootElement;
        if (root.ValueKind != JsonValueKind.Object || !root.TryGetProperty("data", out var data))
        {
            throw new AgentApiException((int) response.StatusCode, "unexpected_response");
        }
        return data.Clone();
    }

    private static async Task EnsureAsync(HttpResponseMessage response, CancellationToken cancellation)
    {
        if (response.StatusCode == HttpStatusCode.OK || response.StatusCode == HttpStatusCode.Created)
        {
            return;
        }
        var body = await response.Content.ReadAsStringAsync(cancellation);
        var reason = "request_failed";
        try
        {
            using var document = JsonDocument.Parse(body);
            if (document.RootElement.TryGetProperty("error", out var error))
            {
                if (error.TryGetProperty("meta", out var meta))
                {
                    if (meta.TryGetProperty("storageCode", out var storageCode)
                        && storageCode.ValueKind == JsonValueKind.String)
                    {
                        reason = storageCode.GetString()!;
                    }
                    else if (meta.TryGetProperty("deviceCode", out var deviceCode)
                        && deviceCode.ValueKind == JsonValueKind.String)
                    {
                        reason = deviceCode.GetString()!;
                    }
                }
                if (reason == "request_failed" && error.TryGetProperty("code", out var code)
                    && code.ValueKind == JsonValueKind.String)
                {
                    reason = code.GetString()!;
                }
            }
        }
        catch (JsonException)
        {
            // The status code still describes the failure; never include untrusted bodies.
        }
        throw new AgentApiException((int) response.StatusCode, reason);
    }

    private static ApiSession ParseSession(JsonElement session) =>
        new(session.GetProperty("id").GetString()!,
            session.GetProperty("studentId").GetString()!,
            session.GetProperty("status").GetString()!,
            session.TryGetProperty("desiredStatus", out var desired) && desired.ValueKind == JsonValueKind.String
                ? desired.GetString() : null,
            session.GetProperty("startedAt").GetString()!);
}

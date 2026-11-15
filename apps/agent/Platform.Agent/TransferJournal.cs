namespace Platform.Agent;

public sealed record DownloadChunk(long Offset, int SizeBytes, string Sha256);

/// <summary>No signed URLs, S3 credentials or bearer tokens are persisted here.</summary>
public sealed class TransferOperation
{
    public string Key { get; set; } = string.Empty;
    public string SessionId { get; set; } = string.Empty;
    public string Path { get; set; } = string.Empty;
    public string Direction { get; set; } = string.Empty;
    public string ExpectedSha256 { get; set; } = string.Empty;
    public long ExpectedSize { get; set; }
    public string? TransferId { get; set; }
    public string Status { get; set; } = "pending";
    public Dictionary<int, string> UploadedParts { get; set; } = new();
    public List<DownloadChunk> DownloadChunks { get; set; } = new();
    public string? RecoveryCopyPath { get; set; }
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

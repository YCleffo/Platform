using System.Globalization;

namespace Platform.Agent;

/// <summary>Per-agent bounds; the server's quota and upload limit remain authoritative.</summary>
public sealed record AgentTransferOptions
{
    public long MaxFileBytes { get; init; } = 10L * 1024 * 1024 * 1024;
    public int Concurrency { get; init; } = 3;
    public long BytesPerSecond { get; init; }
    public int RetryAttempts { get; init; } = 4;
    public int RequestTimeoutSeconds { get; init; } = 900;
    public int RetryBaseMilliseconds { get; init; } = 250;
    public const int BufferSize = 64 * 1024;
    public const int CheckpointBytes = 8 * 1024 * 1024;

    public static AgentTransferOptions FromEnvironment() => new()
    {
        MaxFileBytes = Read("PLATFORM_AGENT_TRANSFER_MAX_BYTES", 10L * 1024 * 1024 * 1024, 1, 5L * 1024 * 1024 * 1024 * 1024),
        Concurrency = (int)Read("PLATFORM_AGENT_TRANSFER_CONCURRENCY", 3, 1, 16),
        BytesPerSecond = Read("PLATFORM_AGENT_TRANSFER_BYTES_PER_SECOND", 0, 0, 10L * 1024 * 1024 * 1024),
        RetryAttempts = (int)Read("PLATFORM_AGENT_TRANSFER_RETRY_ATTEMPTS", 4, 1, 10),
        RequestTimeoutSeconds = (int)Read("PLATFORM_AGENT_TRANSFER_TIMEOUT_SECONDS", 900, 30, 86400),
    };

    public void Validate()
    {
        if (MaxFileBytes < 1 || MaxFileBytes > 5L * 1024 * 1024 * 1024 * 1024 || Concurrency is < 1 or > 16
            || BytesPerSecond < 0 || RetryAttempts is < 1 or > 10 || RequestTimeoutSeconds is < 1 or > 86400
            || RetryBaseMilliseconds < 0 || RetryBaseMilliseconds > 30000)
            throw new InvalidOperationException("Invalid agent transfer bounds.");
    }

    private static long Read(string name, long fallback, long minimum, long maximum)
    {
        var text = Environment.GetEnvironmentVariable(name);
        if (string.IsNullOrEmpty(text)) return fallback;
        if (!long.TryParse(text, NumberStyles.None, CultureInfo.InvariantCulture, out var value) || value < minimum || value > maximum)
            throw new InvalidOperationException("Invalid " + name + ".");
        return value;
    }
}

using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Platform.Agent;

public sealed record ManifestRebaseTarget(string Path, string? OriginalSha256, long OriginalSize,
    string? ConflictCopyPath, ServerFileEntry? Server, string? PreviousSha256 = null, long PreviousSize = 0);
public sealed record ManifestRebasePlan(long Version, ManifestRebaseTarget[] Targets);

public sealed class JournalCorruptedException : IOException
{
    public JournalCorruptedException(string path, Exception? inner = null)
        : base($"Agent journal is corrupted and was preserved for recovery: {path}", inner) { }
}

/// <summary>
/// One durable record of an operation. Phase distinguishes local actions from
/// actions acknowledged by the platform and operations that must be retried.
/// </summary>
public sealed class JournalOperation
{
    public string Kind { get; set; } = string.Empty;
    public string Phase { get; set; } = string.Empty;
    public string? Detail { get; set; }
    public string? SessionId { get; set; }
    public DateTimeOffset At { get; set; } = DateTimeOffset.UtcNow;
}

/// <summary>
/// Durable local agent state: enrollment, credential, current session, the last
/// server-confirmed manifest and the pending manifest. Writes are atomic and
/// keep a previous generation. A damaged journal is never silently replaced
/// with an empty one because it may still describe unsynchronised documents.
/// </summary>
public sealed class StateJournal
{
    public const string FileName = "agent-state.json";

    public int SchemaVersion { get; set; } = 3;
    public string BaseUrl { get; set; } = "http://127.0.0.1:8080";
    public string? DeviceId { get; set; }
    public string? DeviceNumber { get; set; }
    public string? ClassroomName { get; set; }
    public string EnrollmentState { get; set; } = "pending";
    [JsonIgnore] public bool ServerConnected { get; set; }
    public bool DocumentsRestored { get; set; }
    public bool WindowsLogonConfirmed { get; set; }
    public DateTimeOffset? LogonGrantExpiresAt { get; set; }
    public bool StorageConfirmed { get; set; }
    public SessionProgress? Progress { get; set; }
    public SessionError? LastError { get; set; }
    public string? CredentialId { get; set; }
    public string? Credential { get; set; }
    public string? SessionId { get; set; }
    public string SessionStatus { get; set; } = "CLOSED";
    public string? DesiredStatus { get; set; }
    public string? StudentId { get; set; }
    public long LastManifestVersion { get; set; }
    public List<ManifestEntry> ConfirmedManifest { get; set; } = new();
    public long PendingManifestVersion { get; set; }
    public long NextManifestVersion { get; set; }
    public ManifestRebasePlan? RebasePlan { get; set; }
    public List<ManifestEntry> PendingManifest { get; set; } = new();
    public string? DocumentsRoot { get; set; }
    public bool ProfilePrepared { get; set; }
    public bool UserSessionEnded { get; set; }
    public bool CleanupDone { get; set; }
    public List<TransferOperation> Transfers { get; set; } = new();
    [JsonIgnore] public object TransferGate { get; } = new();
    public List<JournalOperation> Operations { get; set; } = new();
    public List<string> RetryOperations { get; set; } = new();

    [JsonIgnore]
    public string Directory { get; set; } = string.Empty;

    [JsonIgnore]
    public bool RecoveredFromBackup { get; set; }

    private static readonly JsonSerializerOptions Options = new()
    {
        WriteIndented = true,
        PropertyNameCaseInsensitive = true,
        Converters = { new JsonStringEnumConverter() },
    };

    public static StateJournal Load(string directory)
    {
        var path = Path.Combine(directory, FileName);
        var backup = path + ".bak";
        if (File.Exists(path))
        {
            return Parse(path, directory, fromBackup: false);
        }
        if (File.Exists(backup))
        {
            return Parse(backup, directory, fromBackup: true);
        }
        return new StateJournal { Directory = directory };
    }

    private static StateJournal Parse(string path, string directory, bool fromBackup)
    {
        try
        {
            var state = JsonSerializer.Deserialize<StateJournal>(File.ReadAllText(path), Options)
                ?? throw new JsonException("Journal is empty.");
            state.Directory = directory;
            state.RecoveredFromBackup = fromBackup;
            return state;
        }
        catch (Exception failure) when (failure is JsonException or NotSupportedException or IOException)
        {
            throw new JournalCorruptedException(path, failure);
        }
    }

    public void Save()
    {
        System.IO.Directory.CreateDirectory(this.Directory);
        var path = Path.Combine(this.Directory, FileName);
        var temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            var json = JsonSerializer.Serialize(this, Options);
            using (var stream = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None,
                       4096, FileOptions.WriteThrough))
            using (var writer = new StreamWriter(stream, new UTF8Encoding(false)))
            {
                writer.Write(json);
                writer.Flush();
                stream.Flush(true);
            }
            if (File.Exists(path))
            {
                File.Replace(temporary, path, path + ".bak", ignoreMetadataErrors: true);
            }
            else
            {
                File.Move(temporary, path);
            }
            StateDirectorySecurity.ProtectFile(path);
        }
        finally
        {
            if (File.Exists(temporary))
            {
                File.Delete(temporary);
            }
        }
    }

    public void Record(string kind, string phase, string? detail = null, string? sessionId = null)
    {
        Operations.Add(new JournalOperation
        {
            Kind = kind,
            Phase = phase,
            Detail = detail,
            SessionId = sessionId,
            At = DateTimeOffset.UtcNow,
        });
        if (Operations.Count > 200)
        {
            Operations.RemoveRange(0, Operations.Count - 200);
        }
    }

    public void RequireRetry(string operation)
    {
        if (!RetryOperations.Contains(operation, StringComparer.Ordinal))
        {
            RetryOperations.Add(operation);
        }
    }

    public void ClearRetry(string operation) =>
        RetryOperations.RemoveAll(candidate => string.Equals(candidate, operation, StringComparison.Ordinal));

    public void StartSession(string sessionId, string studentId, string status, string? desiredStatus)
    {
        if (HasUnfinishedTransfers) throw new AgentIntegrityException("An upload is still unconfirmed; preserve the previous session.");
        Transfers = new();
        SessionId = sessionId;
        StudentId = studentId;
        SessionStatus = status;
        DesiredStatus = desiredStatus;
        LastManifestVersion = 0;
        ConfirmedManifest = new List<ManifestEntry>();
        PendingManifestVersion = 0;
        NextManifestVersion = 0;
        RebasePlan = null;
        PendingManifest = new List<ManifestEntry>();
        DocumentsRoot = null;
        ProfilePrepared = false;
        DocumentsRestored = false;
        WindowsLogonConfirmed = false;
        LogonGrantExpiresAt = null;
        StorageConfirmed = false;
        Progress = null;
        LastError = null;
        UserSessionEnded = false;
        CleanupDone = false;
        RetryOperations = new List<string>();
        Record("session.adopted", "server_confirmed", null, sessionId);
    }

    public void MarkPending(long version, IEnumerable<ManifestEntry> entries)
    {
        PendingManifestVersion = version;
        PendingManifest = entries.ToList();
        RequireRetry($"sync_out:{version}");
    }

    public void MarkConfirmed(long version, IEnumerable<ManifestEntry> entries)
    {
        if (HasUnfinishedTransfers) throw new AgentIntegrityException("Cannot confirm while uploads remain pending.");
        LastManifestVersion = version;
        ConfirmedManifest = entries.ToList();
        PendingManifestVersion = 0;
        NextManifestVersion = 0;
        RebasePlan = null;
        PendingManifest = new List<ManifestEntry>();
        RetryOperations.RemoveAll(operation => operation.StartsWith("sync_out:", StringComparison.Ordinal));
    }

    public void CompleteClosed()
    {
        if (HasUnfinishedTransfers) throw new AgentIntegrityException("Cannot close while uploads remain pending.");
        Transfers = new();
        SessionStatus = SessionStateMachine.Closed;
        DesiredStatus = null;
        SessionId = null;
        StudentId = null;
        DocumentsRoot = null;
        ProfilePrepared = false;
        DocumentsRestored = false;
        WindowsLogonConfirmed = false;
        LogonGrantExpiresAt = null;
        StorageConfirmed = false;
        Progress = null;
        LastError = null;
        UserSessionEnded = false;
        CleanupDone = false;
        LastManifestVersion = 0;
        ConfirmedManifest = new List<ManifestEntry>();
        PendingManifestVersion = 0;
        NextManifestVersion = 0;
        RebasePlan = null;
        PendingManifest = new List<ManifestEntry>();
        RetryOperations = new List<string>();
    }

    public bool HasUnfinishedTransfers => Transfers.Any(transfer => transfer.Direction == "upload" && transfer.Status is not "confirmed" and not "aborted");

    public bool HasUnfinishedDocuments => HasUnfinishedTransfers ||
        !string.IsNullOrEmpty(DocumentsRoot)
        && (!SessionStateMachine.IsTerminal(SessionStatus) || SessionStatus == SessionStateMachine.Failed);
}

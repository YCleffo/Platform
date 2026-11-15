using System.Diagnostics;
using System.Runtime.Versioning;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace Platform.Agent;

/// <summary>Read-only identities of installations predating the Platform rename.</summary>
public static class LegacyInstallation
{
    public const string StateVendor = "KiberOne";
    public const string ServiceName = "KiberOneAgent";
    public const string ExecutableName = "kiberone-agent";
    public const string AccountPrefix = "k1_std_";
    public const string AccountMarker = "KiberOne:";
    public const string TransferStagingName = ".kiberone-transfer-staging";
    public const string TestVmRegistry = @"HKEY_LOCAL_MACHINE\SOFTWARE\KiberOne\Agent\TestVm";
    public const string ReceiptName = "legacy-migration.json";

    public static string DefaultStateRoot(string commonData) => Path.Combine(commonData, "Platform", "Agent");
    public static string PreviousStateRoot(string commonData) => Path.Combine(commonData, StateVendor, "Agent");

    public static string ResolveStateDirectory(string? configured, string commonData)
    {
        var current = Path.GetFullPath(configured ?? DefaultStateRoot(commonData));
        EnsurePreviousServiceStopped();
        EnsureReady(PreviousStateRoot(commonData), current);
        return current;
    }

    public static bool AccountCommentMatches(string? comment, string session, string nonce) =>
        comment == "Platform:" + session + ":" + nonce || comment == AccountMarker + session + ":" + nonce;

    [SupportedOSPlatform("windows")]
    public static string? ReadTestVmValue(string name) => Registry.GetValue(TestVmRegistry, name, null) as string;

    public static void EnsureReady(string previousRoot, string currentRoot)
    {
        ManagedPaths.RejectReparseTree(previousRoot);
        ManagedPaths.RejectReparseTree(currentRoot);
        if (!Directory.Exists(previousRoot) || !Directory.EnumerateFileSystemEntries(previousRoot).Any()) return;
        var receipt = Path.Combine(currentRoot, ReceiptName);
        if (!File.Exists(receipt)) throw new IOException("A previous agent installation has recovery data. Run the explicit legacy-state migration before starting Platform Agent.");
        var migration = JsonSerializer.Deserialize<StateMigrationReceipt>(File.ReadAllText(receipt))
            ?? throw new IOException("Legacy-state migration receipt is invalid.");
        if (migration.Status != "complete" || !Path.GetFullPath(migration.SourceRoot).Equals(Path.GetFullPath(previousRoot), StringComparison.OrdinalIgnoreCase)
            || !File.Exists(Path.Combine(currentRoot, StateJournal.FileName))
            || migration.FileCount < 1 || migration.InventorySha256.Length != 64 || !migration.InventorySha256.All(Uri.IsHexDigit))
            throw new IOException("Legacy-state migration is incomplete. Recovery data must be preserved.");
    }

    public static StateMigrationReceipt MigrateDefaultState(string commonData)
    {
        EnsurePreviousServiceStopped();
        return MigrateState(PreviousStateRoot(commonData), DefaultStateRoot(commonData));
    }

    /// <summary>Copies and verifies a backup and a staged workspace. The old tree remains available for recovery.</summary>
    public static StateMigrationReceipt MigrateState(string previousRoot, string currentRoot)
    {
        previousRoot = Path.GetFullPath(previousRoot);
        currentRoot = Path.GetFullPath(currentRoot);
        if (previousRoot.Equals(currentRoot, StringComparison.OrdinalIgnoreCase)
            || currentRoot.StartsWith(previousRoot + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase)
            || previousRoot.StartsWith(currentRoot + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase))
            throw new IOException("State migration roots must be disjoint.");
        ManagedPaths.RejectReparseTree(previousRoot);
        ManagedPaths.RejectReparseTree(currentRoot);
        if (File.Exists(Path.Combine(currentRoot, ReceiptName)))
        {
            EnsureReady(previousRoot, currentRoot);
            return JsonSerializer.Deserialize<StateMigrationReceipt>(File.ReadAllText(Path.Combine(currentRoot, ReceiptName)))!;
        }
        if (!File.Exists(Path.Combine(previousRoot, StateJournal.FileName)))
            throw new IOException("Previous recovery data has no readable journal. Reconcile it before migration.");
        if (Directory.Exists(currentRoot) && Directory.EnumerateFileSystemEntries(currentRoot).Any())
            throw new IOException("Platform state already contains data. State journals cannot be merged automatically.");
        var state = StateJournal.Load(previousRoot);
        ValidateAccountRecords(previousRoot, state);
        var generation = Guid.NewGuid().ToString("N");
        var backup = currentRoot + ".legacy-backup-" + generation;
        var stage = currentRoot + ".migration-" + generation;
        var inventory = Inventory(previousRoot);
        CopyTree(previousRoot, backup, inventory);
        CopyTree(previousRoot, stage, inventory);
        if (!inventory.SequenceEqual(Inventory(previousRoot))) throw new IOException("Previous state changed while it was being copied.");
        var staged = StateJournal.Load(stage);
        if (staged.DocumentsRoot is { } documents)
        {
            var absolute = Path.GetFullPath(documents);
            var prefix = previousRoot + Path.DirectorySeparatorChar;
            if (absolute.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
                staged.DocumentsRoot = Path.Combine(currentRoot, Path.GetRelativePath(previousRoot, absolute));
        }
        staged.Record("installation.migrated", "verified", generation, staged.SessionId);
        staged.Save();
        var receipt = new StateMigrationReceipt("complete", previousRoot, backup, inventory.Count,
            Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(JsonSerializer.Serialize(inventory)))), DateTimeOffset.UtcNow);
        var receiptPath = Path.Combine(stage, ReceiptName);
        File.WriteAllText(receiptPath, JsonSerializer.Serialize(receipt));
        StateDirectorySecurity.ProtectFile(receiptPath);
        if (Directory.Exists(currentRoot)) Directory.Delete(currentRoot); // Only the validated empty destination is removed.
        Directory.Move(stage, currentRoot);
        EnsureReady(previousRoot, currentRoot);
        return receipt;
    }

    public static void RestoreDownloadPartial(string parent, string key, string destination)
    {
        if (key.Length != 64 || !key.All(Uri.IsHexDigit)) throw new IOException("Invalid partial identity.");
        var previous = Path.Combine(parent, TransferStagingName, key + ".partial");
        if (File.Exists(destination) || !File.Exists(previous)) return;
        ManagedPaths.RejectReparseTree(Path.GetDirectoryName(previous)!);
        CopyVerified(previous, destination);
    }

    private static SortedDictionary<string, string> Inventory(string root) => new(
        Directory.EnumerateFiles(root, "*", SearchOption.AllDirectories).ToDictionary(
            file => Path.GetRelativePath(root, file), ManifestEngine.HashFile), StringComparer.Ordinal);

    private static void CopyTree(string source, string destination, SortedDictionary<string, string> inventory)
    {
        StateDirectorySecurity.ProtectDirectory(destination);
        foreach (var (relative, sha) in inventory)
        {
            var target = Path.Combine(destination, relative);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            CopyVerified(Path.Combine(source, relative), target);
            if (ManifestEngine.HashFile(target) != sha) throw new IOException("State migration verification failed.");
        }
    }

    private static void CopyVerified(string source, string destination)
    {
        using (var input = new FileStream(source, FileMode.Open, FileAccess.Read, FileShare.Read, AgentTransferOptions.BufferSize))
        using (var output = new FileStream(destination, FileMode.CreateNew, FileAccess.Write, FileShare.None,
            AgentTransferOptions.BufferSize, FileOptions.WriteThrough))
        {
            input.CopyTo(output, AgentTransferOptions.BufferSize);
            output.Flush(true);
        }
        StateDirectorySecurity.ProtectFile(destination);
        if (ManifestEngine.HashFile(source) != ManifestEngine.HashFile(destination)) throw new IOException("Recovery copy checksum mismatch.");
    }

    private static void ValidateAccountRecords(string previousRoot, StateJournal state)
    {
        var accounts = Path.Combine(previousRoot, "accounts");
        if (!Directory.Exists(accounts)) return;
        foreach (var path in Directory.EnumerateFiles(accounts, "account.json", SearchOption.AllDirectories))
        {
            using var record = JsonDocument.Parse(File.ReadAllText(path));
            var value = record.RootElement;
            var closed = value.TryGetProperty("CleanupDone", out var cleanup) && cleanup.ValueKind == JsonValueKind.True;
            if (!closed && (!value.TryGetProperty("SessionId", out var session) || session.GetString() != state.SessionId))
                throw new IOException("An account recovery record belongs to another unfinished session. Reconcile it before migration.");
        }
    }

    private static void EnsurePreviousServiceStopped()
    {
        if (OperatingSystem.IsWindows() && Process.GetProcessesByName(ExecutableName).Length > 0)
            throw new IOException("Stop the previous agent service before migrating or starting Platform Agent.");
    }
}

public sealed record StateMigrationReceipt(string Status, string SourceRoot, string BackupRoot, int FileCount,
    string InventorySha256, DateTimeOffset CreatedAt);

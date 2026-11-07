using System.Security.Cryptography;

namespace KiberOne.Agent;

public enum ManifestOperation { Add, Update, Delete }

/// <summary>One entry of a Documents manifest: relative path, operation, hash, size and optional baseline.</summary>
public sealed record ManifestEntry(string Path, ManifestOperation Operation, string Sha256, long Size,
    string? BaseSha256 = null, int? Version = null)
{
    public const int MaxEntryCount = 10000;
    public const long MaxEntrySize = 256L * 1024 * 1024;
    public const int MaxPathLength = 1024;
}

public sealed record ServerFileEntry(string Path, string Sha256, long Size, int Version);

/// <summary>
/// Builds manifests of a Documents root and computes the diff between two
/// manifests. Paths are relative and slash-normalized; only regular files
/// under the allowed root are considered, symlinks/reparse points are rejected.
/// </summary>
public static class ManifestEngine
{
    public static ManifestEntry[] Build(string root)
    {
        var absolute = Path.GetFullPath(root);
        if (!Directory.Exists(absolute))
        {
            return Array.Empty<ManifestEntry>();
        }
        var files = new List<ManifestEntry>();
        foreach (var file in Directory.EnumerateFiles(absolute, "*", SearchOption.AllDirectories))
        {
            if ((File.GetAttributes(file) & FileAttributes.ReparsePoint) != 0)
            {
                throw new IOException("Reparse point is not allowed inside Documents.");
            }
            var info = new FileInfo(file);
            if (info.Length > ManifestEntry.MaxEntrySize)
            {
                throw new IOException("A file inside Documents exceeds the supported size.");
            }
            var relative = Normalize(Path.GetRelativePath(absolute, file));
            ValidateRelative(relative);
            files.Add(new ManifestEntry(relative, ManifestOperation.Add, HashFile(file), info.Length));
        }
        if (files.Count > ManifestEntry.MaxEntryCount)
        {
            throw new IOException("Too many files inside Documents.");
        }
        var duplicate = files.GroupBy(entry => entry.Path, StringComparer.OrdinalIgnoreCase)
            .FirstOrDefault(group => group.Count() > 1);
        if (duplicate is not null)
        {
            throw new IOException("Documents contain case-insensitive duplicate paths.");
        }
        return files.OrderBy(entry => entry.Path, StringComparer.OrdinalIgnoreCase).ToArray();
    }

    /// <summary>Diff from the last accepted manifest to the current one.</summary>
    public static ManifestEntry[] Diff(ManifestEntry[] previous, ManifestEntry[] current)
    {
        var previousByPath = previous.ToDictionary(entry => entry.Path, StringComparer.OrdinalIgnoreCase);
        var currentByPath = current.ToDictionary(entry => entry.Path, StringComparer.OrdinalIgnoreCase);
        var result = new List<ManifestEntry>();
        foreach (var entry in current)
        {
            if (!previousByPath.TryGetValue(entry.Path, out var before))
            {
                result.Add(entry with { Operation = ManifestOperation.Add, BaseSha256 = null, Version = null });
            }
            else if (!string.Equals(before.Sha256, entry.Sha256, StringComparison.OrdinalIgnoreCase)
                || before.Size != entry.Size)
            {
                result.Add(entry with
                {
                    Operation = ManifestOperation.Update,
                    BaseSha256 = before.Sha256,
                    Version = before.Version,
                });
            }
        }
        foreach (var before in previous)
        {
            if (!currentByPath.ContainsKey(before.Path))
            {
                result.Add(before with
                {
                    Operation = ManifestOperation.Delete,
                    BaseSha256 = before.Sha256,
                    Sha256 = before.Sha256,
                });
            }
        }
        return result.OrderBy(entry => entry.Path, StringComparer.OrdinalIgnoreCase).ToArray();
    }

    public static ManifestEntry[] Confirmed(ServerFileEntry[] files) =>
        files.Select(file => new ManifestEntry(Normalize(file.Path), ManifestOperation.Add,
                file.Sha256.ToLowerInvariant(), file.Size, null, file.Version))
            .OrderBy(entry => entry.Path, StringComparer.OrdinalIgnoreCase)
            .ToArray();

    public static bool SameEntries(ManifestEntry[] left, ManifestEntry[] right)
    {
        if (left.Length != right.Length)
        {
            return false;
        }
        var orderedLeft = left.OrderBy(entry => entry.Path, StringComparer.OrdinalIgnoreCase).ToArray();
        var orderedRight = right.OrderBy(entry => entry.Path, StringComparer.OrdinalIgnoreCase).ToArray();
        for (var index = 0; index < orderedLeft.Length; index++)
        {
            var a = orderedLeft[index];
            var b = orderedRight[index];
            if (!string.Equals(a.Path, b.Path, StringComparison.OrdinalIgnoreCase)
                || a.Operation != b.Operation
                || !string.Equals(a.Sha256, b.Sha256, StringComparison.OrdinalIgnoreCase)
                || a.Size != b.Size
                || !string.Equals(a.BaseSha256, b.BaseSha256, StringComparison.OrdinalIgnoreCase))
            {
                return false;
            }
        }
        return true;
    }

    public static string Normalize(string path) => path.Replace('\\', '/').TrimStart('/');

    public static void ValidateRelative(string relative)
    {
        if (relative.StartsWith('/') || relative.StartsWith('\\') || Path.IsPathRooted(relative))
        {
            throw new IOException("Documents path must be relative.");
        }
        var normalized = Normalize(relative);
        if (normalized.Length == 0 || normalized.Length > ManifestEntry.MaxPathLength
            || normalized.StartsWith('/') || normalized.EndsWith('/')
            || normalized.Contains('\0') || normalized.Contains(':')
            || normalized.Split('/').Any(segment => segment is "" or "." or ".."))
        {
            throw new IOException("Unsafe Documents path.");
        }
    }

    /// <summary>Resolves a relative manifest path, rejecting traversal and reparse points.</summary>
    public static string ResolvePath(string root, string relative)
    {
        ValidateRelative(relative);
        var absoluteRoot = Path.GetFullPath(root);
        var candidate = Path.GetFullPath(Path.Combine(absoluteRoot,
            Normalize(relative).Replace('/', Path.DirectorySeparatorChar)));
        var prefix = absoluteRoot.EndsWith(Path.DirectorySeparatorChar)
            ? absoluteRoot : absoluteRoot + Path.DirectorySeparatorChar;
        if (!candidate.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
        {
            throw new IOException("Documents path escapes the isolated root.");
        }
        var current = absoluteRoot;
        foreach (var segment in Normalize(relative).Split('/').SkipLast(1))
        {
            current = Path.Combine(current, segment);
            if (Directory.Exists(current)
                && (File.GetAttributes(current) & FileAttributes.ReparsePoint) != 0)
            {
                throw new IOException("Reparse point is not allowed inside Documents.");
            }
        }
        return candidate;
    }

    /// <summary>Writes a downloaded file atomically inside the isolated Documents root.</summary>
    public static void PlaceAtomic(string root, string relative, byte[] content)
    {
        var target = ResolvePath(root, relative);
        var directory = Path.GetDirectoryName(target)
            ?? throw new IOException("Documents target has no parent directory.");
        Directory.CreateDirectory(directory);
        var temporary = target + ".kiberone-" + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            using (var stream = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None,
                       64 * 1024, FileOptions.WriteThrough))
            {
                stream.Write(content);
                stream.Flush(true);
            }
            File.Move(temporary, target, overwrite: true);
        }
        finally
        {
            if (File.Exists(temporary))
            {
                File.Delete(temporary);
            }
        }
    }

    public static bool ContentMatches(string file, string sha256, long size)
    {
        var info = new FileInfo(file);
        return info.Exists && info.Length == size
            && string.Equals(HashFile(file), sha256, StringComparison.OrdinalIgnoreCase);
    }

    public static string HashFile(string file)
    {
        using var stream = new FileStream(file, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        return Convert.ToHexStringLower(SHA256.HashData(stream));
    }

    public static string HashBytes(byte[] bytes) => Convert.ToHexStringLower(SHA256.HashData(bytes));
}

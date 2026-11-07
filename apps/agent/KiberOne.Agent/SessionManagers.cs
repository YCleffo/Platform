namespace KiberOne.Agent;

/// <summary>Operations the agent performs on the managed Windows profile.</summary>
public interface IWindowsSessionManager
{
    /// <summary>Create a temporary local Windows account and return its profile Documents path.</summary>
    Task<string> PrepareProfileAsync(string studentId, CancellationToken cancellation);

    /// <summary>Close user processes and sign the user out.</summary>
    Task EndUserSessionAsync(string studentId, CancellationToken cancellation);

    /// <summary>Remove the temporary profile. Only called after storage confirmed.</summary>
    Task CleanupProfileAsync(string studentId, CancellationToken cancellation);

    /// <summary>True when a previous profile for the student may still hold unsynced files.</summary>
    Task<bool> HasRecoveryStateAsync(string studentId, CancellationToken cancellation);
}

/// <summary>
/// Isolated development adapter: all "Windows" operations happen inside a
/// dedicated test directory. It never touches real Windows accounts, Documents
/// or profiles of the developer machine. It is not a proof of Windows Logon
/// correctness.
/// </summary>
public sealed class TestSessionManager : IWindowsSessionManager
{
    public string Root { get; }

    public TestSessionManager(string root)
    {
        Root = Path.GetFullPath(root);
        Directory.CreateDirectory(Root);
    }

    public string ProfileRoot(string studentId)
    {
        var safe = string.Concat(studentId.Where(char.IsAsciiLetterOrDigit)).ToLowerInvariant();
        return Path.Combine(Root, safe.Length == 0 ? "profile" : safe);
    }

    public Task<string> PrepareProfileAsync(string studentId, CancellationToken cancellation)
    {
        cancellation.ThrowIfCancellationRequested();
        var documents = Path.Combine(ProfileRoot(studentId), "Documents");
        Directory.CreateDirectory(documents);
        return Task.FromResult(documents);
    }

    public Task EndUserSessionAsync(string studentId, CancellationToken cancellation)
    {
        cancellation.ThrowIfCancellationRequested();
        File.WriteAllText(Path.Combine(ProfileRoot(studentId), "session-ended.marker"), DateTimeOffset.UtcNow.ToString("O"));
        return Task.CompletedTask;
    }

    public Task CleanupProfileAsync(string studentId, CancellationToken cancellation)
    {
        cancellation.ThrowIfCancellationRequested();
        if (Directory.Exists(ProfileRoot(studentId)))
        {
            Directory.Delete(ProfileRoot(studentId), recursive: true);
        }
        return Task.CompletedTask;
    }

    public Task<bool> HasRecoveryStateAsync(string studentId, CancellationToken cancellation)
    {
        cancellation.ThrowIfCancellationRequested();
        return Task.FromResult(Directory.Exists(ProfileRoot(studentId)));
    }
}

/// <summary>
/// Real Windows profile management skeleton. Explicitly NOT implemented: it
/// must run only inside a dedicated test Windows VM with the dangerous mode
/// enabled. Never invoke it on a developer machine.
/// </summary>
public sealed class WindowsSessionManager : IWindowsSessionManager
{
    public static bool DangerousModeEnabled { get; set; }

    private static void Guard()
    {
        if (!DangerousModeEnabled)
        {
            throw new NotSupportedException(
                "Real Windows profile operations are disabled outside the dedicated test VM. " +
                "Enable WindowsSessionManager.DangerousModeEnabled explicitly in that VM only.");
        }
    }

    public Task<string> PrepareProfileAsync(string studentId, CancellationToken cancellation)
    {
        Guard();
        throw new NotSupportedException("Windows profile creation is not implemented yet; use the isolated TestSessionManager.");
    }

    public Task EndUserSessionAsync(string studentId, CancellationToken cancellation)
    {
        Guard();
        throw new NotSupportedException("Windows logoff is not implemented yet; use the isolated TestSessionManager.");
    }

    public Task CleanupProfileAsync(string studentId, CancellationToken cancellation)
    {
        Guard();
        throw new NotSupportedException("Windows profile removal is not implemented yet; use the isolated TestSessionManager.");
    }

    public Task<bool> HasRecoveryStateAsync(string studentId, CancellationToken cancellation)
    {
        Guard();
        throw new NotSupportedException("Windows recovery inspection is not implemented yet.");
    }
}

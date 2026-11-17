using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;

namespace KiberOne.Agent;

/// <summary>Operations the agent performs on the managed Windows profile.</summary>
public interface IWindowsSessionManager
{
    void BindSession(string sessionId, string studentId) { }
    bool IsTestAdapter => false;
    string? ManagedSid => null;
    Task<bool> IsWindowsLogonConfirmedAsync(CancellationToken cancellation) => Task.FromResult(false);
    void ConfirmStorage(string sessionId) { }

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
    private readonly Dictionary<string, string> _profiles = new(StringComparer.Ordinal);
    public bool IsTestAdapter => true;
    public void BindSession(string sessionId, string studentId) => _profiles[studentId] = sessionId;
    public Task<bool> IsWindowsLogonConfirmedAsync(CancellationToken cancellation) => Task.FromResult(false);

    public TestSessionManager(string root)
    {
        Root = Path.GetFullPath(root);
        Directory.CreateDirectory(Root);
    }

    public string ProfileRoot(string studentId)
    {
        var identity = _profiles.TryGetValue(studentId, out var sessionId) ? sessionId : studentId;
        var key = Convert.ToHexStringLower(SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(identity)));
        return Path.Combine(Root, key);
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
            var profile = ProfileRoot(studentId);
            ManagedPaths.RejectReparseTree(profile);
            Directory.Delete(profile, recursive: true);
        }
        return Task.CompletedTask;
    }

    public Task<bool> HasRecoveryStateAsync(string studentId, CancellationToken cancellation)
    {
        cancellation.ThrowIfCancellationRequested();
        return Task.FromResult(Directory.Exists(ProfileRoot(studentId)));
    }
}

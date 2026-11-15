using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace Platform.Agent;

/// <summary>Native operations are fail-closed and are never used by the test adapter.</summary>
public sealed class WindowsSessionManager : IWindowsSessionManager
{
    public static bool DangerousModeEnabled { get; set; }
    private readonly string _recoveryRoot;
    private string? _sessionId;
    private string? _studentId;
    private ManagedAccount? _account;
    public string? ManagedSid => _account?.Sid;
    public bool IsTestAdapter => false;

    public WindowsSessionManager(string? recoveryRoot = null) => _recoveryRoot = Path.GetFullPath(recoveryRoot
        ?? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Platform", "Agent", "accounts"));

    // Hash the entire session identity. An existing account is NEVER accepted on the basis of its name.
    public static string ResolveUsername(string sessionId) => "platform_" +
        Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(sessionId)))[..11];
    public static string ResolveProfileRoot(string sessionId) => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Platform", "Agent", "accounts",
        Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(sessionId))));

    public void BindSession(string sessionId, string studentId)
    {
        if (!Guid.TryParse(sessionId, out _) || string.IsNullOrWhiteSpace(studentId))
            throw new AgentIntegrityException("A managed account requires an authorized DeviceSession identity.");
        _sessionId = sessionId;
        _studentId = studentId;
        ManagedPaths.RejectReparseTree(RecordDirectory);
        _account = File.Exists(RecordPath) ? JsonSerializer.Deserialize<ManagedAccount>(File.ReadAllText(RecordPath))
            ?? throw new AgentIntegrityException("Managed account journal is empty.") : null;
        if (_account is not null && (_account.SessionId != sessionId || _account.StudentId != studentId))
            throw new AgentIntegrityException("Managed account ownership mismatch.");
    }

    public static void Guard()
    {
        if (!OperatingSystem.IsWindows() || !DangerousModeEnabled ||
            Environment.GetEnvironmentVariable("PLATFORM_AGENT_DANGEROUS_MODE") != "true")
            throw new NotSupportedException("Native Windows operations require a separately authorized test VM.");
        VerifyTestVm();
    }

    [SupportedOSPlatform("windows")]
    private static void VerifyTestVm()
    {
        // Both an administrator-provisioned machine allow-list AND independent firmware evidence are required.
        var machine = Registry.GetValue(@"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Cryptography", "MachineGuid", null) as string;
        var permit = Registry.GetValue(@"HKEY_LOCAL_MACHINE\SOFTWARE\Platform\Agent\TestVm", "MachineGuid", null) as string
            ?? LegacyInstallation.ReadTestVmValue("MachineGuid");
        var expiry = Registry.GetValue(@"HKEY_LOCAL_MACHINE\SOFTWARE\Platform\Agent\TestVm", "ExpiresAt", null) as string
            ?? LegacyInstallation.ReadTestVmValue("ExpiresAt");
        var bios = string.Join(' ', Registry.GetValue(@"HKEY_LOCAL_MACHINE\HARDWARE\DESCRIPTION\System\BIOS", "SystemManufacturer", ""),
            Registry.GetValue(@"HKEY_LOCAL_MACHINE\HARDWARE\DESCRIPTION\System\BIOS", "SystemProductName", ""));
        var virtualMachine = new[] { "virtual machine", "vmware", "virtualbox", "kvm", "qemu", "xen", "parallels" }
            .Any(value => bios.Contains(value, StringComparison.OrdinalIgnoreCase));
        if (string.IsNullOrEmpty(machine) || machine != permit || !virtualMachine ||
            !DateTimeOffset.TryParse(expiry, out var expiresAt) || expiresAt <= DateTimeOffset.UtcNow)
            throw new NotSupportedException("This machine has no valid test VM permit or virtual firmware evidence.");
    }

    private string RecordDirectory => Path.Combine(_recoveryRoot, _sessionId ?? throw new AgentIntegrityException("Session is not bound."));
    private string RecordPath => Path.Combine(RecordDirectory, "account.json");
    private ManagedAccount Account(string studentId)
    {
        if (_sessionId is null || _studentId != studentId || _account is null)
            throw new AgentIntegrityException("Managed account is not bound to this student session.");
        return _account;
    }
    private void Save()
    {
        ManagedPaths.RejectReparseTree(RecordDirectory);
        StateDirectorySecurity.ProtectDirectory(RecordDirectory);
        var temporary = RecordPath + ".tmp";
        File.WriteAllText(temporary, JsonSerializer.Serialize(_account));
        StateDirectorySecurity.ProtectFile(temporary);
        File.Move(temporary, RecordPath, true);
    }

    public Task<string> PrepareProfileAsync(string studentId, CancellationToken cancellation)
    {
        Guard();
        cancellation.ThrowIfCancellationRequested();
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException();
        return Task.FromResult(PrepareWindowsProfile(studentId));
    }

    [SupportedOSPlatform("windows")]
    private string PrepareWindowsProfile(string studentId)
    {
        using var privileges = new WindowsProfilePrivilegeScope();
        if (_sessionId is null || _studentId != studentId) throw new AgentIntegrityException("Session is not bound.");
        if (_account is null)
        {
            _account = new ManagedAccount
            {
                SessionId = _sessionId, StudentId = studentId, Username = ResolveUsername(_sessionId),
                OwnerNonce = Convert.ToHexStringLower(RandomNumberGenerator.GetBytes(24)),
                ProtectedPassword = WindowsSecret.Protect(Encoding.Unicode.GetBytes(Convert.ToBase64String(RandomNumberGenerator.GetBytes(32)) + "!aA1")),
            };
            Save(); // Durable intent before account creation permits recovery of a partially created account.
        }
        var account = Account(studentId);
        if (account.CleanupDone) throw new AgentIntegrityException("A cleaned account cannot be reused.");
        var passwordBytes = WindowsSecret.Unprotect(account.ProtectedPassword);
        try
        {
            var password = Encoding.Unicode.GetString(passwordBytes);
            var info = new UserInfo1 { Name = account.Username, Password = password, Privilege = 1,
                Comment = "Platform:" + account.SessionId + ":" + account.OwnerNonce, Flags = 0x1 | 0x10000 };
            if (account.Sid is null)
            {
                var result = NetUserAdd(null, 1, ref info, out _);
                if (result != 0 && result != 2224) throw new Win32Exception((int)result, "NetUserAdd failed.");
                ValidateAccountMarker(account); // Existing accounts with another ownership marker are rejected.
                account.Sid = ((SecurityIdentifier)new NTAccount(Environment.MachineName, account.Username)
                    .Translate(typeof(SecurityIdentifier))).Value;
                Save();
            }
            ValidateIdentity(account);
            if (!LogonUser(account.Username, ".", password, 2, 0, out var token)) throw new Win32Exception();
            try
            {
                using (var identity = new WindowsIdentity(token))
                {
                    var administrators = new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null);
                    if (identity.Groups?.Contains(administrators) == true)
                        throw new AgentIntegrityException("Managed Windows accounts must remain standard users.");
                }
                var profile = new ProfileInfo { Size = Marshal.SizeOf<ProfileInfo>(), Flags = 1, UserName = account.Username };
                if (!LoadUserProfile(token, ref profile)) throw new Win32Exception();
                try
                {
                    var documentsId = new Guid("FDD39AD0-238F-46AF-ADB4-6C85480369C7");
                    Marshal.ThrowExceptionForHR(SHGetKnownFolderPath(ref documentsId, 0x8000, token, out var documents));
                    try { account.DocumentsPath = Marshal.PtrToStringUni(documents) ?? throw new IOException("Missing Documents path."); }
                    finally { Marshal.FreeCoTaskMem(documents); }
                    var length = 32768;
                    var path = new StringBuilder(length);
                    if (!GetUserProfileDirectory(token, path, ref length)) throw new Win32Exception();
                    account.ProfilePath = Path.GetFullPath(path.ToString());
                    var prefix = account.ProfilePath.TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
                    if (!Path.GetFullPath(account.DocumentsPath).StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
                        throw new AgentIntegrityException("Redirected Documents requires a separately authorized storage policy.");
                    ManagedPaths.RejectReparseAncestors(account.ProfilePath);
                    ManagedPaths.RejectReparseAncestors(account.DocumentsPath);
                    Directory.CreateDirectory(account.DocumentsPath);
                    var acl = new DirectoryInfo(account.DocumentsPath).GetAccessControl();
                    acl.SetAccessRuleProtection(true, false);
                    foreach (FileSystemAccessRule rule in acl.GetAccessRules(true, true, typeof(SecurityIdentifier)))
                        acl.RemoveAccessRuleSpecific(rule);
                    foreach (var sid in new[] { account.Sid!, "S-1-5-18", "S-1-5-32-544" })
                        acl.AddAccessRule(new FileSystemAccessRule(new SecurityIdentifier(sid),
                            sid == account.Sid ? FileSystemRights.Modify : FileSystemRights.FullControl,
                            InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None, AccessControlType.Allow));
                    new DirectoryInfo(account.DocumentsPath).SetAccessControl(acl);
                    account.ProfilePrepared = true;
                    Save();
                }
                finally { if (!UnloadUserProfile(token, profile.Profile)) throw new Win32Exception(); }
            }
            finally { CloseHandle(token); }
            return account.DocumentsPath!;
        }
        finally { CryptographicOperations.ZeroMemory(passwordBytes); }
    }

    public Task<bool> IsWindowsLogonConfirmedAsync(CancellationToken cancellation)
    {
        Guard(); cancellation.ThrowIfCancellationRequested();
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException();
        if (_account?.Sid is not { } sid) return Task.FromResult(false);
        ValidateIdentity(_account);
        return Task.FromResult(FindSessions(sid).Count > 0);
    }

    public Task EndUserSessionAsync(string studentId, CancellationToken cancellation)
    {
        Guard(); cancellation.ThrowIfCancellationRequested();
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException();
        var account = Account(studentId);
        ValidateIdentity(account);
        foreach (var session in FindSessions(account.Sid!))
            if (!WTSLogoffSession(IntPtr.Zero, session, true)) throw new Win32Exception();
        if (FindSessions(account.Sid!).Count > 0) throw new IOException("Managed Windows session is still running.");
        account.SessionEnded = true; Save();
        return Task.CompletedTask;
    }

    public void ConfirmStorage(string sessionId)
    {
        if (_account?.SessionId != sessionId || !_account.SessionEnded)
            throw new AgentIntegrityException("Storage confirmation belongs to another or running session.");
        _account.StorageConfirmed = true; Save();
    }

    public Task CleanupProfileAsync(string studentId, CancellationToken cancellation)
    {
        Guard(); cancellation.ThrowIfCancellationRequested();
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException();
        using var privileges = new WindowsProfilePrivilegeScope();
        var account = Account(studentId);
        if (account.CleanupDone) return Task.CompletedTask;
        if (!account.SessionEnded || !account.StorageConfirmed || FindSessions(account.Sid!).Count != 0)
            throw new AgentIntegrityException("Cleanup requires ended Windows session and confirmed Storage.");
        if (!account.ProfileDeleted)
        {
            ValidateIdentity(account);
            if (account.ProfilePath is { } profile && Directory.Exists(profile))
            {
                ManagedPaths.RejectReparseAncestors(profile);
                if (!DeleteProfile(account.Sid!, profile, null)) throw new Win32Exception();
            }
            account.ProfileDeleted = true; Save();
        }
        // The identity marker and SID are checked again before removing the local account.
        var result = NetUserGetInfo(null, account.Username, 1, out var buffer);
        if (result == 0)
        {
            NetApiBufferFree(buffer);
            ValidateIdentity(account);
            var deleted = NetUserDel(null, account.Username);
            if (deleted != 0) throw new Win32Exception((int)deleted, "NetUserDel failed.");
        }
        else if (result != 2221) throw new Win32Exception((int)result, "Cannot inspect account before cleanup.");
        account.CleanupDone = true;
        account.ProtectedPassword = Array.Empty<byte>();
        Save(); // Keep an ownership tombstone for idempotent retry, without credentials.
        return Task.CompletedTask;
    }

    public Task<bool> HasRecoveryStateAsync(string studentId, CancellationToken cancellation)
    {
        cancellation.ThrowIfCancellationRequested();
        return Task.FromResult(_account is { CleanupDone: false } && _studentId == studentId);
    }

    public byte[] CreateLogonEnvelope(string sessionId, string nonce, DateTimeOffset expiresAt)
    {
        Guard();
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException();
        if (_account is not { ProfilePrepared: true, SessionEnded: false } account || account.SessionId != sessionId
            || expiresAt <= DateTimeOffset.UtcNow || expiresAt > DateTimeOffset.UtcNow.AddSeconds(31))
            throw new AgentIntegrityException("Invalid native logon grant.");
        ValidateIdentity(account);
        var password = WindowsSecret.Unprotect(account.ProtectedPassword);
        try
        {
            // Native wire format: PLG1 magic, UTC FILETIME expiry, length-prefixed UTF-16 fields.
            // No credential is ever returned by the ordinary UI IPC endpoint.
            using var stream = new MemoryStream();
            using (var writer = new BinaryWriter(stream, Encoding.Unicode, leaveOpen: true))
            {
                writer.Write(0x31474c50U);
                writer.Write(expiresAt.UtcDateTime.ToFileTimeUtc());
                foreach (var field in new[] { sessionId, nonce, account.Sid!, account.Username })
                {
                    var bytes = Encoding.Unicode.GetBytes(field);
                    writer.Write(bytes.Length);
                    writer.Write(bytes);
                }
                writer.Write(password.Length);
                writer.Write(password);
            }
            try { return WindowsSecret.Protect(stream.ToArray()); }
            finally { CryptographicOperations.ZeroMemory(stream.GetBuffer().AsSpan(0, (int)stream.Length)); }
        }
        finally { CryptographicOperations.ZeroMemory(password); }
    }

    [SupportedOSPlatform("windows")]
    private static void ValidateIdentity(ManagedAccount account)
    {
        ValidateAccountMarker(account);
        var sid = ((SecurityIdentifier)new NTAccount(Environment.MachineName, account.Username).Translate(typeof(SecurityIdentifier))).Value;
        if (sid != account.Sid) throw new AgentIntegrityException("Managed account SID mismatch.");
    }
    private static void ValidateAccountMarker(ManagedAccount account)
    {
        var result = NetUserGetInfo(null, account.Username, 1, out var buffer);
        if (result != 0) throw new Win32Exception((int)result, "Cannot inspect managed account.");
        try
        {
            var info = Marshal.PtrToStructure<UserInfo1>(buffer);
            if (!LegacyInstallation.AccountCommentMatches(info.Comment, account.SessionId, account.OwnerNonce) || info.Privilege != 1)
                throw new AgentIntegrityException("Existing Windows account is not owned by this DeviceSession.");
        }
        finally { NetApiBufferFree(buffer); }
    }
    [SupportedOSPlatform("windows")]
    private static List<int> FindSessions(string sid)
    {
        if (!WTSEnumerateSessions(IntPtr.Zero, 0, 1, out var buffer, out var count)) throw new Win32Exception();
        var result = new List<int>();
        try
        {
            for (var index = 0; index < count; index++)
            {
                var session = Marshal.PtrToStructure<WtsSession>(buffer + index * Marshal.SizeOf<WtsSession>());
                if (session.Id == 0 || session.State is 6 or 8 or 9) continue;
                if (!WTSQueryUserToken(session.Id, out var token))
                {
                    var error = Marshal.GetLastWin32Error();
                    if (error == 1008) continue; // This station has no logged-on user.
                    throw new Win32Exception(error, "Cannot verify Windows session ownership.");
                }
                try { using var identity = new WindowsIdentity(token); if (identity.User?.Value == sid) result.Add(session.Id); }
                finally { CloseHandle(token); }
            }
        }
        finally { WTSFreeMemory(buffer); }
        return result;
    }

    private sealed class ManagedAccount
    {
        public string SessionId { get; set; } = "";
        public string StudentId { get; set; } = "";
        public string Username { get; set; } = "";
        public string OwnerNonce { get; set; } = "";
        public string? Sid { get; set; }
        public byte[] ProtectedPassword { get; set; } = Array.Empty<byte>();
        public string? ProfilePath { get; set; }
        public string? DocumentsPath { get; set; }
        public bool ProfilePrepared { get; set; }
        public bool SessionEnded { get; set; }
        public bool StorageConfirmed { get; set; }
        public bool ProfileDeleted { get; set; }
        public bool CleanupDone { get; set; }
    }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct UserInfo1
    {
        public string Name; public string? Password; public uint PasswordAge; public uint Privilege;
        public string? Home; public string? Comment; public uint Flags; public string? Script;
    }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct ProfileInfo
    {
        public int Size; public int Flags; public string UserName; public string? ProfilePath;
        public string? DefaultPath; public string? ServerName; public string? PolicyPath; public IntPtr Profile;
    }
    [StructLayout(LayoutKind.Sequential)]
    private struct WtsSession { public int Id; public IntPtr Station; public int State; }
    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern uint NetUserAdd(string? server, uint level, ref UserInfo1 info, out uint error);
    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern uint NetUserGetInfo(string? server, string user, uint level, out IntPtr info);
    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern uint NetUserDel(string? server, string user);
    [DllImport("netapi32.dll")] private static extern uint NetApiBufferFree(IntPtr buffer);
    [DllImport("advapi32.dll", EntryPoint = "LogonUserW", CharSet = CharSet.Unicode, SetLastError = true)] private static extern bool LogonUser(string user, string domain, string password, int type, int provider, out IntPtr token);
    [DllImport("userenv.dll", EntryPoint = "LoadUserProfileW", CharSet = CharSet.Unicode, SetLastError = true)] private static extern bool LoadUserProfile(IntPtr token, ref ProfileInfo profile);
    [DllImport("userenv.dll", SetLastError = true)] private static extern bool UnloadUserProfile(IntPtr token, IntPtr profile);
    [DllImport("userenv.dll", EntryPoint = "GetUserProfileDirectoryW", CharSet = CharSet.Unicode, SetLastError = true)] private static extern bool GetUserProfileDirectory(IntPtr token, StringBuilder path, ref int length);
    [DllImport("userenv.dll", EntryPoint = "DeleteProfileW", CharSet = CharSet.Unicode, SetLastError = true)] private static extern bool DeleteProfile(string sid, string path, string? computer);
    [DllImport("shell32.dll")] private static extern int SHGetKnownFolderPath(ref Guid id, uint flags, IntPtr token, out IntPtr path);
    [DllImport("kernel32.dll")] private static extern bool CloseHandle(IntPtr handle);
    [DllImport("wtsapi32.dll", EntryPoint = "WTSEnumerateSessionsW", SetLastError = true)] private static extern bool WTSEnumerateSessions(IntPtr server, int reserved, int version, out IntPtr buffer, out int count);
    [DllImport("wtsapi32.dll")] private static extern void WTSFreeMemory(IntPtr buffer);
    [DllImport("wtsapi32.dll", SetLastError = true)] private static extern bool WTSQueryUserToken(int sessionId, out IntPtr token);
    [DllImport("wtsapi32.dll", SetLastError = true)] private static extern bool WTSLogoffSession(IntPtr server, int sessionId, bool wait);
}

internal static class WindowsSecret
{
    [StructLayout(LayoutKind.Sequential)] private struct Blob { public int Length; public IntPtr Data; }
    [DllImport("crypt32.dll", SetLastError = true)] private static extern bool CryptProtectData(ref Blob input, string? description, IntPtr entropy, IntPtr reserved, IntPtr prompt, uint flags, out Blob output);
    [DllImport("crypt32.dll", SetLastError = true)] private static extern bool CryptUnprotectData(ref Blob input, IntPtr description, IntPtr entropy, IntPtr reserved, IntPtr prompt, uint flags, out Blob output);
    [DllImport("kernel32.dll")] private static extern IntPtr LocalFree(IntPtr memory);
    public static byte[] Protect(byte[] secret) => Transform(secret, true);
    public static byte[] Unprotect(byte[] secret) => Transform(secret, false);
    private static byte[] Transform(byte[] secret, bool protect)
    {
        var input = new Blob { Length = secret.Length, Data = Marshal.AllocHGlobal(secret.Length) };
        try
        {
            Marshal.Copy(secret, 0, input.Data, secret.Length);
            Blob output;
            var success = protect ? CryptProtectData(ref input, null, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, 5, out output)
                : CryptUnprotectData(ref input, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, 1, out output);
            if (!success) throw new Win32Exception();
            try { var result = new byte[output.Length]; Marshal.Copy(output.Data, result, 0, result.Length); return result; }
            finally { for (var index = 0; index < output.Length; index++) Marshal.WriteByte(output.Data, index, 0); LocalFree(output.Data); }
        }
        finally
        {
            for (var index = 0; index < secret.Length; index++) Marshal.WriteByte(input.Data, index, 0);
            Marshal.FreeHGlobal(input.Data);
            if (protect) CryptographicOperations.ZeroMemory(secret);
        }
    }
}

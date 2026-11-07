using System.Runtime.Versioning;
using System.Security.AccessControl;
using System.Security.Principal;

namespace KiberOne.Agent;

/// <summary>
/// Protects the agent-only state directory. The journal contains the device
/// credential, so standard users (including the interactive student profile)
/// must not be able to read it.
/// </summary>
public static class StateDirectorySecurity
{
    public static void ProtectDirectory(string directory)
    {
        Directory.CreateDirectory(directory);
        if (!OperatingSystem.IsWindows())
        {
            return;
        }
        ProtectWindowsDirectory(directory);
    }

    public static void ProtectFile(string file)
    {
        if (!OperatingSystem.IsWindows() || !File.Exists(file))
        {
            return;
        }
        ProtectWindowsFile(file);
    }

    [SupportedOSPlatform("windows")]
    private static void ProtectWindowsDirectory(string directory)
    {
        var security = new DirectorySecurity();
        security.SetAccessRuleProtection(isProtected: true, preserveInheritance: false);
        AddDirectoryRule(security, WellKnownSidType.LocalSystemSid, FileSystemRights.FullControl);
        AddDirectoryRule(security, WellKnownSidType.BuiltinAdministratorsSid, FileSystemRights.FullControl);
        if (WindowsIdentity.GetCurrent().User is { } currentUser)
        {
            security.AddAccessRule(new FileSystemAccessRule(currentUser, FileSystemRights.FullControl,
                InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None,
                AccessControlType.Allow));
        }
        new DirectoryInfo(directory).SetAccessControl(security);
    }

    [SupportedOSPlatform("windows")]
    private static void ProtectWindowsFile(string file)
    {
        var security = new FileSecurity();
        security.SetAccessRuleProtection(isProtected: true, preserveInheritance: false);
        AddFileRule(security, WellKnownSidType.LocalSystemSid, FileSystemRights.FullControl);
        AddFileRule(security, WellKnownSidType.BuiltinAdministratorsSid, FileSystemRights.FullControl);
        if (WindowsIdentity.GetCurrent().User is { } currentUser)
        {
            security.AddAccessRule(new FileSystemAccessRule(currentUser, FileSystemRights.FullControl,
                AccessControlType.Allow));
        }
        new FileInfo(file).SetAccessControl(security);
    }

    [SupportedOSPlatform("windows")]
    private static void AddDirectoryRule(DirectorySecurity security, WellKnownSidType sid, FileSystemRights rights)
    {
        var identity = new SecurityIdentifier(sid, null);
        security.AddAccessRule(new FileSystemAccessRule(identity, rights,
            InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None,
            AccessControlType.Allow));
    }

    [SupportedOSPlatform("windows")]
    private static void AddFileRule(FileSecurity security, WellKnownSidType sid, FileSystemRights rights)
    {
        var identity = new SecurityIdentifier(sid, null);
        security.AddAccessRule(new FileSystemAccessRule(identity, rights, AccessControlType.Allow));
    }
}

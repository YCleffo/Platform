using System.ComponentModel;
using System.IO.Pipes;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.Principal;
using Microsoft.Win32.SafeHandles;

namespace KiberOne.Agent;

public static class AgentPipeIdentity
{
    [SupportedOSPlatform("windows")]
    public static void VerifyServer(NamedPipeClientStream pipe, bool allowCurrentUserForConsole = false)
    {
        // The service explicitly sets the kernel object's owner to SYSTEM. Checking
        // that owner also works for standard users who cannot query a SYSTEM process token.
        var owner = pipe.GetAccessControl().GetOwner(typeof(SecurityIdentifier)) as SecurityIdentifier;
        using var caller = WindowsIdentity.GetCurrent();
        if (owner?.IsWellKnown(WellKnownSidType.LocalSystemSid) == true) return;
        if (!allowCurrentUserForConsole || owner != caller.User)
            throw new UnauthorizedAccessException("The pipe is not owned by the trusted agent service.");
        if (!GetNamedPipeServerProcessId(pipe.SafePipeHandle, out var processId)) throw new Win32Exception();
        var process = OpenProcess(0x1000, false, processId);
        if (process == IntPtr.Zero) throw new Win32Exception();
        try
        {
            if (!OpenProcessToken(process, 8, out var token)) throw new Win32Exception();
            try
            {
                using var identity = new WindowsIdentity(token);
                using var current = WindowsIdentity.GetCurrent();
                if (identity.User?.IsWellKnown(WellKnownSidType.LocalSystemSid) != true
                    && (!allowCurrentUserForConsole || identity.User != current.User))
                    throw new UnauthorizedAccessException("The named pipe is not hosted by the trusted agent service.");
            }
            finally { CloseHandle(token); }
        }
        finally { CloseHandle(process); }
    }
    [DllImport("kernel32.dll", SetLastError = true)] private static extern bool GetNamedPipeServerProcessId(SafePipeHandle pipe, out uint processId);
    [DllImport("kernel32.dll", SetLastError = true)] private static extern IntPtr OpenProcess(uint access, bool inherit, uint processId);
    [DllImport("advapi32.dll", SetLastError = true)] private static extern bool OpenProcessToken(IntPtr process, uint access, out IntPtr token);
    [DllImport("kernel32.dll")] private static extern bool CloseHandle(IntPtr handle);
}

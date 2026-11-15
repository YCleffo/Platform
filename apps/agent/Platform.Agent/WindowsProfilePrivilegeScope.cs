using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;

namespace Platform.Agent;

/// <summary>Temporarily enables only the privileges required by the supported profile APIs.</summary>
[SupportedOSPlatform("windows")]
internal sealed class WindowsProfilePrivilegeScope : IDisposable
{
    private IntPtr _token;
    private Privileges _previous;
    private bool _adjusted;

    public WindowsProfilePrivilegeScope()
    {
        if (!OpenProcessToken(GetCurrentProcess(), 0x20 | 0x8, out _token)) throw new Win32Exception();
        try
        {
            if (!LookupPrivilegeValue(null, "SeBackupPrivilege", out var backup)
                || !LookupPrivilegeValue(null, "SeRestorePrivilege", out var restore)) throw new Win32Exception();
            var requested = new Privileges { Count = 2,
                First = new() { Id = backup, Attributes = 2 }, Second = new() { Id = restore, Attributes = 2 } };
            var success = AdjustTokenPrivileges(_token, false, ref requested, Marshal.SizeOf<Privileges>(), out _previous, out _);
            var error = Marshal.GetLastWin32Error();
            _adjusted = success;
            if (!success || error == 1300) throw new Win32Exception(error, "Windows profile privileges are not available.");
        }
        catch
        {
            if (_adjusted) RestoreTokenPrivileges(_token, false, ref _previous, 0, IntPtr.Zero, IntPtr.Zero);
            CloseHandle(_token); _token = IntPtr.Zero;
            throw;
        }
    }

    public void Dispose()
    {
        if (_token == IntPtr.Zero) return;
        try
        {
            if (_adjusted && !RestoreTokenPrivileges(_token, false, ref _previous, 0, IntPtr.Zero, IntPtr.Zero))
                throw new Win32Exception();
        }
        finally { CloseHandle(_token); _token = IntPtr.Zero; }
    }

    [StructLayout(LayoutKind.Sequential)] private struct Luid { public uint Low; public int High; }
    [StructLayout(LayoutKind.Sequential)] private struct Entry { public Luid Id; public uint Attributes; }
    [StructLayout(LayoutKind.Sequential)] private struct Privileges { public uint Count; public Entry First; public Entry Second; }
    [DllImport("kernel32.dll")] private static extern IntPtr GetCurrentProcess();
    [DllImport("kernel32.dll")] private static extern bool CloseHandle(IntPtr handle);
    [DllImport("advapi32.dll", SetLastError = true)] private static extern bool OpenProcessToken(IntPtr process, uint access, out IntPtr token);
    [DllImport("advapi32.dll", EntryPoint = "LookupPrivilegeValueW", CharSet = CharSet.Unicode, SetLastError = true)] private static extern bool LookupPrivilegeValue(string? system, string name, out Luid value);
    [DllImport("advapi32.dll", SetLastError = true)] private static extern bool AdjustTokenPrivileges(IntPtr token, bool disableAll, ref Privileges state, int length, out Privileges previous, out int returnedLength);
    [DllImport("advapi32.dll", EntryPoint = "AdjustTokenPrivileges", SetLastError = true)] private static extern bool RestoreTokenPrivileges(IntPtr token, bool disableAll, ref Privileges state, int length, IntPtr previous, IntPtr returnedLength);
}

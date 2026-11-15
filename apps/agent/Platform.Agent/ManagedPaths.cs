namespace Platform.Agent;

public static class ManagedPaths
{
    public static void RejectReparseAncestors(string path)
    {
        var root = new DirectoryInfo(Path.GetFullPath(path));
        for (DirectoryInfo? current = root; current is not null; current = current.Parent)
            if (current.Exists && (current.Attributes & FileAttributes.ReparsePoint) != 0)
                throw new IOException("Managed resource contains a reparse point.");
    }

    public static void RejectReparseTree(string path)
    {
        RejectReparseAncestors(path);
        var root = new DirectoryInfo(Path.GetFullPath(path));
        if (!root.Exists) return;
        var pending = new Stack<DirectoryInfo>();
        pending.Push(root);
        while (pending.Count > 0)
            foreach (var item in pending.Pop().EnumerateFileSystemInfos())
            {
                if ((item.Attributes & FileAttributes.ReparsePoint) != 0)
                    throw new IOException("Managed resource contains a reparse point.");
                if (item is DirectoryInfo directory) pending.Push(directory);
            }
    }
}

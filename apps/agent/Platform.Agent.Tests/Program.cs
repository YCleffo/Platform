using Platform.Agent;

namespace Platform.Agent.Tests;

public static class Program
{
    public static int Main()
    {
        var tests = new (string Name, Action Body)[]
        {
            ("state machine allows the full happy path", StateMachineFullPath),
            ("state machine rejects invalid transitions", StateMachineRejects),
            ("state machine blocks next session on SYNC_PENDING", StateMachineBlocks),
            ("manifest build produces relative hashed entries", ManifestBuild),
            ("manifest diff detects add/update/delete", ManifestDiff),
            ("manifest normalization rejects traversal-style paths", ManifestNormalization),
            ("journal survives atomic save and reload", JournalRoundTrip),
            ("legacy state upgrade retains documents, account ownership and verified backup", LegacyStateUpgrade),
            ("legacy state upgrade refuses divergent journals and orphaned recovery", LegacyUpgradeRejectsDivergence),
            ("legacy download partial is copied with verified bytes", LegacyDownloadPartial),
            ("corrupt journal is preserved instead of replaced", JournalCorruption),
            ("test session manager is isolated per student", SessionManagerIsolation),
            ("agent prepares a profile and stays ACTIVE without an end command", AgentStaysActive),
            ("agent scenario A to B to A keeps documents isolated", AgentScenario),
            ("agent retries sync-out after a network failure without cleanup", AgentNetworkRecovery),
            ("agent keeps the profile when storage confirmation fails", AgentConfirmRetry),
            ("agent resumes from a restarted journal", AgentRestart),
            ("agent ignores an expired end command", AgentExpiredCommand),
            ("sync-in network recovery retries restoration before any upload", AgentSyncInRecovery),
            ("missing Documents blocks deletion and preserves server files", AgentMissingDocuments),
            ("prepared profile alone never confirms native Windows logon", AgentAwaitsWindowsLogon),
            ("lost native logon after restart preserves Documents for recovery", AgentLostWindowsLogon),
            ("partial preparation failure reports retry and recovers safely", AgentPreparationRetry),
            ("cleanup failure retries only after durable storage confirmation", AgentCleanupRetry),
            ("keep_server preserves the unique local version before cleanup", AgentConflictRebase),
            ("conflict recovery resumes after a journal restart", AgentConflictRebaseRestart),
            ("server deletion retains the unique local conflict copy", AgentDeletedConflictRebase),
            ("an empty rebase diff still creates a replacement confirmation", AgentEmptyConflictRebase),
            ("windows session manager rejects calls when dangerous mode disabled", WindowsSessionManagerRejectsWhenDisabled),
            ("windows session manager dangerous mode requires environment variable", WindowsSessionManagerRequiresEnv),
            ("windows session manager formats sanitized username and profile root", WindowsSessionManagerFormatting),
        };
        var failed = 0;
        foreach (var (name, body) in tests)
        {
            try
            {
                body();
                Console.WriteLine($"PASS {name}");
            }
            catch (Exception failure)
            {
                failed++;
                Console.WriteLine($"FAIL {name}: {failure.Message}");
            }
        }
        Console.WriteLine(failed == 0 ? "All agent tests passed." : $"{failed} test(s) failed.");
        return failed == 0 ? 0 : 1;
    }

    private static void StateMachineFullPath()
    {
        var path = new[]
        {
            SessionStateMachine.Authorized, SessionStateMachine.Preparing, SessionStateMachine.WindowsProfileReady,
            SessionStateMachine.SyncingIn, SessionStateMachine.Active, SessionStateMachine.Ending,
            SessionStateMachine.SyncingOut, SessionStateMachine.StorageConfirmed, SessionStateMachine.Cleanup,
            SessionStateMachine.Closed,
        };
        for (var i = 0; i < path.Length - 1; i++)
        {
            Assert.True(SessionStateMachine.Allows(path[i], path[i + 1]),
                $"{path[i]} -> {path[i + 1]} must be allowed");
        }
        Assert.True(SessionStateMachine.Allows(SessionStateMachine.SyncingOut, SessionStateMachine.SyncPending), "sync pending recovery");
        Assert.True(SessionStateMachine.Allows(SessionStateMachine.SyncPending, SessionStateMachine.SyncingOut), "resume from sync pending");
        Assert.True(SessionStateMachine.IsTerminal(SessionStateMachine.Closed), "CLOSED is terminal");
        Assert.True(SessionStateMachine.BlocksNextSession(SessionStateMachine.RecoveryRequired), "recovery blocks next session");
    }

    private static void StateMachineRejects()
    {
        Assert.True(!SessionStateMachine.Allows(SessionStateMachine.Active, SessionStateMachine.Cleanup), "cleanup from active is forbidden");
        Assert.True(!SessionStateMachine.Allows(SessionStateMachine.SyncingOut, SessionStateMachine.Closed), "close before storage confirmation is forbidden");
        Assert.True(!SessionStateMachine.Allows(SessionStateMachine.Closed, SessionStateMachine.Active), "closed session cannot reopen");
        Assert.True(!SessionStateMachine.Allows(SessionStateMachine.Preparing, "NONSENSE"), "unknown state rejected");
    }

    private static void StateMachineBlocks()
    {
        Assert.True(SessionStateMachine.BlocksNextSession(SessionStateMachine.SyncPending), "SYNC_PENDING blocks");
        Assert.True(SessionStateMachine.BlocksNextSession(SessionStateMachine.RecoveryRequired), "RECOVERY_REQUIRED blocks");
        Assert.True(!SessionStateMachine.BlocksNextSession(SessionStateMachine.Closed), "CLOSED allows next session");
    }

    private static void ManifestBuild()
    {
        var root = TemporaryDirectory();
        try
        {
            Directory.CreateDirectory(Path.Combine(root, "nested"));
            File.WriteAllText(Path.Combine(root, "a.txt"), "alpha");
            File.WriteAllText(Path.Combine(root, "nested", "b.txt"), "beta");
            var manifest = ManifestEngine.Build(root);
            Assert.Equal(2, manifest.Length, "two files found");
            Assert.Equal("a.txt", manifest[0].Path, "relative slash-normalized path");
            Assert.Equal("nested/b.txt", manifest[1].Path, "nested path normalized");
            Assert.Equal(ManifestEngine.HashBytes("alpha"u8.ToArray()), manifest[0].Sha256, "sha256 of content");
        }
        finally
        {
            Directory.Delete(root, recursive: true);
        }
    }

    private static void ManifestDiff()
    {
        var previous = new[]
        {
            new ManifestEntry("keep.txt", ManifestOperation.Add, "aaaa", 4),
            new ManifestEntry("changed.txt", ManifestOperation.Add, "bbbb", 4),
            new ManifestEntry("deleted.txt", ManifestOperation.Add, "cccc", 4),
        };
        var current = new[]
        {
            new ManifestEntry("keep.txt", ManifestOperation.Add, "aaaa", 4),
            new ManifestEntry("changed.txt", ManifestOperation.Add, "cccc", 4),
            new ManifestEntry("added.txt", ManifestOperation.Add, "dddd", 4),
        };
        var diff = ManifestEngine.Diff(previous, current);
        Assert.Equal(3, diff.Length, "three changed entries");
        Assert.True(diff.Any(entry => entry.Path == "changed.txt" && entry.Operation == ManifestOperation.Update
            && entry.BaseSha256 == "bbbb"), "update detects its baseline");
        Assert.True(diff.Any(entry => entry.Path == "deleted.txt" && entry.Operation == ManifestOperation.Delete
            && entry.BaseSha256 == "cccc"), "delete detects its baseline");
        Assert.True(diff.Any(entry => entry.Path == "added.txt" && entry.Operation == ManifestOperation.Add), "add detected");
        Assert.True(diff.All(entry => entry.Path != "keep.txt"), "unchanged file excluded");
    }

    private static void ManifestNormalization()
    {
        Assert.Equal("docs/a.txt", ManifestEngine.Normalize("\\docs\\a.txt"), "backslashes normalized");
        Assert.Equal("docs/a.txt", ManifestEngine.Normalize("/docs/a.txt"), "leading slash stripped");
        foreach (var unsafePath in new[] { "../x", "a/../b", "/abs", "C:\\x", "a//b", "a/./b" })
        {
            var rejected = false;
            try
            {
                ManifestEngine.ValidateRelative(unsafePath);
            }
            catch (IOException)
            {
                rejected = true;
            }
            Assert.True(rejected, $"unsafe path rejected: {unsafePath}");
        }
    }

    private static void JournalRoundTrip()
    {
        var directory = TemporaryDirectory();
        try
        {
            var journal = StateJournal.Load(directory);
            journal.DeviceId = "device-1";
            journal.Credential = "secret";
            journal.SessionStatus = SessionStateMachine.Active;
            journal.MarkConfirmed(3, new[]
            {
                new ManifestEntry("a.txt", ManifestOperation.Add, "aaaa", 4, null, 1),
            });
            journal.Save();
            var loaded = StateJournal.Load(directory);
            Assert.Equal("device-1", loaded.DeviceId ?? string.Empty, "device id persists");
            Assert.Equal("secret", loaded.Credential ?? string.Empty, "credential persists");
            Assert.Equal(SessionStateMachine.Active, loaded.SessionStatus, "session status persists");
            Assert.Equal(3L, loaded.LastManifestVersion, "confirmed version persists");
            Assert.Equal(1, loaded.ConfirmedManifest.Count, "confirmed manifest persists");
            Assert.Equal("a.txt", loaded.ConfirmedManifest[0].Path, "manifest path persists");
        }
        finally
        {
            Directory.Delete(directory, recursive: true);
        }
    }

    private static void JournalCorruption()
    {
        var directory = TemporaryDirectory();
        try
        {
            var path = Path.Combine(directory, StateJournal.FileName);
            File.WriteAllText(path, "{ this is not valid json");
            var threw = false;
            try
            {
                StateJournal.Load(directory);
            }
            catch (JournalCorruptedException)
            {
                threw = true;
            }
            Assert.True(threw, "corrupt journal raises a recovery error");
            Assert.Equal("{ this is not valid json", File.ReadAllText(path), "corrupt journal is preserved");
        }
        finally
        {
            Directory.Delete(directory, recursive: true);
        }
    }

    private static void SessionManagerIsolation()
    {
        var root = TemporaryDirectory();
        try
        {
            var manager = new TestSessionManager(root);
            var documents = manager.PrepareProfileAsync("student-a", CancellationToken.None).GetAwaiter().GetResult();
            File.WriteAllText(Path.Combine(documents, "work.txt"), "unsynced");
            Assert.True(manager.HasRecoveryStateAsync("student-a", CancellationToken.None).GetAwaiter().GetResult(),
                "recovery state detected for student-a");
            Assert.True(!manager.HasRecoveryStateAsync("student-b", CancellationToken.None).GetAwaiter().GetResult(),
                "student-b sees no foreign recovery state");
            manager.CleanupProfileAsync("student-a", CancellationToken.None).GetAwaiter().GetResult();
            Assert.True(!manager.HasRecoveryStateAsync("student-a", CancellationToken.None).GetAwaiter().GetResult(),
                "cleanup removes the profile");
        }
        finally
        {
            Directory.Delete(root, recursive: true);
        }
    }

    private static void AgentStaysActive()
    {
        using var harness = Harness.Create();
        harness.Server.PutStudentFile("student-a", "welcome.txt", "hello");
        harness.Server.StartSession("student-a");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        var documents = harness.Documents("student-a");
        Assert.Equal("hello", File.ReadAllText(Path.Combine(documents, "welcome.txt")), "sync-in restored the file");
        for (var index = 0; index < 5; index++)
        {
            harness.Tick();
        }
        Assert.Equal(SessionStateMachine.Active, harness.State.SessionStatus, "ACTIVE never ends on its own");
        Assert.Equal("ACTIVE", harness.Server.SessionStatus ?? string.Empty, "server session remains ACTIVE");
        Assert.True(Directory.Exists(documents), "profile remains while active");
    }

    private static void AgentScenario()
    {
        using var harness = Harness.Create();
        var server = harness.Server;
        server.PutStudentFile("student-a", "docs/a.txt", "A original");
        server.StartSession("student-a");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        var docsA = harness.Documents("student-a");
        Assert.Equal("A original", File.ReadAllText(Path.Combine(docsA, "docs", "a.txt")), "A receives own documents");

        File.WriteAllText(Path.Combine(docsA, "docs", "a.txt"), "A changed");
        File.WriteAllText(Path.Combine(docsA, "notes.txt"), "A notes");
        server.RequestEnd();
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Closed);
        Assert.Equal("A changed", server.ReadStudentFile("student-a", "docs/a.txt") ?? string.Empty, "A changes uploaded");
        Assert.Equal("A notes", server.ReadStudentFile("student-a", "notes.txt") ?? string.Empty, "A new file uploaded");
        Assert.True(!Directory.Exists(harness.ProfileRoot("student-a")), "A profile cleaned only after storage confirmation");
        AssertTransitionOrder(server, "ENDING", "SYNCING_OUT", "STORAGE_CONFIRMED", "CLEANUP", "CLOSED");

        server.PutStudentFile("student-b", "docs/b.txt", "B only");
        server.StartSession("student-b");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        var docsB = harness.Documents("student-b");
        Assert.Equal("B only", File.ReadAllText(Path.Combine(docsB, "docs", "b.txt")), "B receives own documents");
        Assert.True(!File.Exists(Path.Combine(docsB, "docs", "a.txt")), "B cannot see A documents");
        Assert.True(!File.Exists(Path.Combine(docsB, "notes.txt")), "B cannot see A notes");
        server.RequestEnd();
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Closed);
        Assert.True(!Directory.Exists(harness.ProfileRoot("student-b")), "B profile cleaned");

        server.StartSession("student-a");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        docsA = harness.Documents("student-a");
        Assert.Equal("A changed", File.ReadAllText(Path.Combine(docsA, "docs", "a.txt")), "A edits restored");
        Assert.Equal("A notes", File.ReadAllText(Path.Combine(docsA, "notes.txt")), "A new file restored");
        Assert.True(!File.Exists(Path.Combine(docsA, "docs", "b.txt")), "A never receives B documents");
        server.RequestEnd();
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Closed);
    }

    private static void AgentNetworkRecovery()
    {
        using var harness = Harness.Create();
        var server = harness.Server;
        server.PutStudentFile("student-a", "a.txt", "base");
        server.StartSession("student-a");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        var documents = harness.Documents("student-a");
        File.WriteAllText(Path.Combine(documents, "a.txt"), "changed");
        server.FailNext("upload", 4);
        server.RequestEnd();
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.SyncPending);
        Assert.True(Directory.Exists(harness.ProfileRoot("student-a")), "network failure never cleans the profile");
        Assert.Equal("base", server.ReadStudentFile("student-a", "a.txt") ?? string.Empty, "unconfirmed change is not stored");
        Assert.Equal("SYNC_PENDING", server.SessionStatus ?? string.Empty, "server records the retryable state");

        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Closed);
        Assert.Equal("changed", server.ReadStudentFile("student-a", "a.txt") ?? string.Empty, "retry uploads the change");
        Assert.True(!Directory.Exists(harness.ProfileRoot("student-a")), "cleanup happens after confirmation");
    }

    private static void AgentConfirmRetry()
    {
        using var harness = Harness.Create();
        var server = harness.Server;
        server.PutStudentFile("student-a", "a.txt", "base");
        server.StartSession("student-a");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        var documents = harness.Documents("student-a");
        File.WriteAllText(Path.Combine(documents, "b.txt"), "new");
        server.FailConfirmCount = 3;
        server.RequestEnd();
        for (var index = 0; index < 2; index++)
        {
            harness.Tick();
        }
        Assert.True(Directory.Exists(harness.ProfileRoot("student-a")), "profile survives unconfirmed storage");
        Assert.True(harness.State.SessionStatus is not SessionStateMachine.Closed, "session is not closed before confirmation");

        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Closed);
        Assert.Equal("new", server.ReadStudentFile("student-a", "b.txt") ?? string.Empty, "file stored after retry");
        Assert.True(!Directory.Exists(harness.ProfileRoot("student-a")), "profile removed after confirmation");
    }

    private static void AgentRestart()
    {
        using var harness = Harness.Create();
        var server = harness.Server;
        server.PutStudentFile("student-a", "a.txt", "base");
        server.StartSession("student-a");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        File.WriteAllText(Path.Combine(harness.Documents("student-a"), "a.txt"), "after restart");
        var reloaded = StateJournal.Load(harness.StateDirectory);
        Assert.Equal(SessionStateMachine.Active, reloaded.SessionStatus, "journal restores ACTIVE");
        Assert.True(reloaded.ConfirmedManifest.Single(entry => entry.Path == "a.txt").Sha256
            == ManifestEngine.HashBytes("base"u8.ToArray()), "confirmed manifest persisted");
        var loop = new AgentLoop(reloaded, new AgentApiClient(server.CreateHttpClient()),
            new TestSessionManager(harness.ProfileRootDirectory), _ => Task.CompletedTask);
        loop.TickOnceAsync(CancellationToken.None).GetAwaiter().GetResult();
        Assert.Equal(SessionStateMachine.Active, reloaded.SessionStatus, "restart does not end the session");
        server.RequestEnd();
        RunUntil(loop, reloaded, () => reloaded.SessionStatus == SessionStateMachine.Closed);
        Assert.Equal("after restart", server.ReadStudentFile("student-a", "a.txt") ?? string.Empty, "restart retry uploads changes");
    }

    private static void AgentExpiredCommand()
    {
        using var harness = Harness.Create();
        var server = harness.Server;
        server.PutStudentFile("student-a", "a.txt", "base");
        server.StartSession("student-a");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        server.QueueExpiredEnd();
        for (var index = 0; index < 3; index++)
        {
            harness.Tick();
        }
        Assert.Equal(SessionStateMachine.Active, harness.State.SessionStatus, "expired end command is ignored");
        Assert.Equal("ACTIVE", server.SessionStatus ?? string.Empty, "server remains active");
        Assert.True(Directory.Exists(harness.ProfileRoot("student-a")), "expired command does not close the profile");
    }

    private static void AssertTransitionOrder(FakeAgentServer server, params string[] expected)
    {
        var previousIndex = -1;
        foreach (var status in expected)
        {
            var index = server.Transitions.LastIndexOf(status);
            Assert.True(index > previousIndex, $"{status} must be reported in order");
            previousIndex = index;
        }
    }

    private static void RunUntil(AgentLoop loop, StateJournal state, Func<bool> predicate, int maximum = 80)
    {
        for (var index = 0; index < maximum && !predicate(); index++)
        {
            loop.TickOnceAsync(CancellationToken.None).GetAwaiter().GetResult();
        }
        Assert.True(predicate(), $"expected state, current is {state.SessionStatus}");
    }

    private static void LegacyStateUpgrade()
    {
        var root = TemporaryDirectory();
        try
        {
            var previous = LegacyInstallation.PreviousStateRoot(root);
            var current = LegacyInstallation.DefaultStateRoot(root);
            var state = StateJournal.Load(previous);
            state.SessionId = Guid.NewGuid().ToString();
            state.StudentId = "upgrade-student";
            state.SessionStatus = SessionStateMachine.SyncPending;
            state.DocumentsRoot = Path.Combine(previous, "test-profiles", "Documents");
            Directory.CreateDirectory(state.DocumentsRoot);
            var document = Path.Combine(state.DocumentsRoot, "unique.txt");
            File.WriteAllText(document, "UNCONFIRMED UNIQUE DOCUMENT");
            state.Save();
            var account = Path.Combine(previous, "accounts", state.SessionId, "account.json");
            Directory.CreateDirectory(Path.GetDirectoryName(account)!);
            File.WriteAllText(account, System.Text.Json.JsonSerializer.Serialize(new
            {
                state.SessionId, state.StudentId, Username = LegacyInstallation.AccountPrefix + "identity",
                CleanupDone = false, Sid = "S-1-5-21-1234",
            }));
            try { LegacyInstallation.EnsureReady(previous, current); throw new InvalidOperationException("Unmigrated legacy state was accepted."); }
            catch (IOException) { }
            var receipt = LegacyInstallation.MigrateState(previous, current);
            var migrated = StateJournal.Load(current);
            Assert.Equal(state.SessionId, migrated.SessionId!, "unfinished session survives upgrade");
            Assert.Equal(state.SessionStatus, migrated.SessionStatus, "recovery status survives upgrade");
            Assert.Equal(Path.Combine(current, "test-profiles", "Documents"), migrated.DocumentsRoot!, "copied test documents point at the copied tree");
            Assert.Equal("UNCONFIRMED UNIQUE DOCUMENT", File.ReadAllText(document), "old unique bytes retained");
            Assert.Equal("UNCONFIRMED UNIQUE DOCUMENT", File.ReadAllText(Path.Combine(migrated.DocumentsRoot!, "unique.txt")), "new tree contains verified bytes");
            Assert.Equal(ManifestEngine.HashFile(account), ManifestEngine.HashFile(Path.Combine(current, "accounts", state.SessionId, "account.json")), "native SID and credentials retained");
            Assert.True(Directory.Exists(receipt.BackupRoot), "verified backup retained outside source");
            Assert.Equal(receipt.InventorySha256, LegacyInstallation.MigrateState(previous, current).InventorySha256, "repeat migration uses its durable receipt");
            Assert.True(LegacyInstallation.AccountCommentMatches(LegacyInstallation.AccountMarker + "session:nonce", "session", "nonce"), "exact historical owner marker accepted");
            Assert.True(!LegacyInstallation.AccountCommentMatches(LegacyInstallation.AccountMarker + "other:nonce", "session", "nonce"), "foreign owner marker rejected");
        }
        finally { Directory.Delete(root, true); }
    }

    private static void LegacyUpgradeRejectsDivergence()
    {
        var root = TemporaryDirectory();
        try
        {
            var previous = LegacyInstallation.PreviousStateRoot(root);
            var current = LegacyInstallation.DefaultStateRoot(root);
            StateJournal.Load(previous).Save();
            StateJournal.Load(current).Save();
            try { LegacyInstallation.MigrateState(previous, current); throw new InvalidOperationException("Divergent journals were merged."); }
            catch (IOException) { }
            var account = Path.Combine(previous, "accounts", "orphan", "account.json");
            Directory.CreateDirectory(Path.GetDirectoryName(account)!);
            File.WriteAllText(account, "{\"SessionId\":\"orphan\",\"CleanupDone\":false}");
            var other = Path.Combine(root, "fresh-target");
            try { LegacyInstallation.MigrateState(previous, other); throw new InvalidOperationException("Orphaned account recovery was discarded."); }
            catch (IOException) { }
            Assert.True(!Directory.Exists(other), "no fresh state created over unresolved recovery");
            Assert.True(File.Exists(account), "orphaned recovery record retained");
        }
        finally { Directory.Delete(root, true); }
    }

    private static void LegacyDownloadPartial()
    {
        var root = TemporaryDirectory();
        try
        {
            var key = new string('a', 64);
            var previous = Path.Combine(root, LegacyInstallation.TransferStagingName);
            Directory.CreateDirectory(previous);
            var source = Path.Combine(previous, key + ".partial");
            File.WriteAllText(source, "verified partial bytes");
            var current = Path.Combine(root, ".platform-transfer-staging");
            Directory.CreateDirectory(current);
            var target = Path.Combine(current, key + ".partial");
            LegacyInstallation.RestoreDownloadPartial(root, key, target);
            Assert.Equal(ManifestEngine.HashFile(source), ManifestEngine.HashFile(target), "partial content survives rename");
            Assert.True(File.Exists(source), "source partial remains available for recovery");
        }
        finally { Directory.Delete(root, true); }
    }

    private static string TemporaryDirectory()
    {
        var path = Path.Combine(Path.GetTempPath(), "platform-agent-tests-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(path);
        return path;
    }

    private static void AgentSyncInRecovery()
    {
        using var harness = Harness.Create();
        harness.Server.PutStudentFile("student-a", "a.txt", "saved");
        harness.Server.StartSession("student-a");
        harness.Server.FailNext("download", 4);
        harness.Tick();
        Assert.Equal(SessionStateMachine.SyncPending, harness.State.SessionStatus, "restore failure remains pending");
        Assert.True(!harness.State.DocumentsRestored && !harness.State.UserSessionEnded, "no completed restore or signout");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        Assert.Equal("saved", File.ReadAllText(Path.Combine(harness.Documents("student-a"), "a.txt")), "retry restores the server file");
        Assert.True(!harness.Server.Transitions.Contains(SessionStateMachine.SyncingOut), "partial restore is never uploaded");
    }

    private static void AgentMissingDocuments()
    {
        using var harness = Harness.Create();
        harness.Server.PutStudentFile("student-a", "a.txt", "saved");
        harness.Server.StartSession("student-a");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        Directory.Delete(harness.Documents("student-a"), true);
        harness.Server.RequestEnd();
        harness.Tick();
        Assert.Equal(SessionStateMachine.RecoveryRequired, harness.State.SessionStatus, "missing root requires recovery");
        Assert.Equal("saved", harness.Server.ReadStudentFile("student-a", "a.txt")!, "missing disk is not interpreted as file deletion");
        Assert.True(!harness.State.CleanupDone, "profile cleanup is blocked");
    }

    private static void AgentAwaitsWindowsLogon()
    {
        using var harness = Harness.Create();
        harness.Server.StartSession("student-a");
        var manager = new UnconfirmedLogonManager(new TestSessionManager(harness.ProfileRootDirectory));
        var loop = new AgentLoop(harness.State, new AgentApiClient(harness.Server.CreateHttpClient()), manager);
        loop.TickOnceAsync(default).GetAwaiter().GetResult();
        Assert.True(harness.State.ProfilePrepared && harness.State.DocumentsRestored, "profile and Documents are prepared");
        Assert.Equal(SessionStateMachine.SyncingIn, harness.State.SessionStatus, "no ACTIVE without real logon evidence");
        Assert.True(!harness.State.WindowsLogonConfirmed, "test profile cannot prove native Windows logon");
    }

    private static void AgentLostWindowsLogon()
    {
        using var harness = Harness.Create();
        harness.Server.StartSession("student-a");
        // This is a controlled WTS contract double, never evidence of native logon.
        var manager = new UnconfirmedLogonManager(new TestSessionManager(harness.ProfileRootDirectory)) { Confirmed = true };
        var loop = new AgentLoop(harness.State, new AgentApiClient(harness.Server.CreateHttpClient()), manager);
        loop.TickOnceAsync(default).GetAwaiter().GetResult();
        Assert.Equal(SessionStateMachine.Active, harness.State.SessionStatus, "contract double supplies initial token evidence");
        var documents = harness.State.DocumentsRoot!;
        File.WriteAllText(Path.Combine(documents, "unsynced.txt"), "retain after reboot");
        var recovered = StateJournal.Load(harness.StateDirectory);
        manager.Confirmed = false;
        loop = new AgentLoop(recovered, new AgentApiClient(harness.Server.CreateHttpClient()), manager);
        loop.TickOnceAsync(default).GetAwaiter().GetResult();
        Assert.Equal(SessionStateMachine.RecoveryRequired, recovered.SessionStatus, "stale journal cannot prove current WTS logon");
        Assert.True(!recovered.WindowsLogonConfirmed && !recovered.CleanupDone, "recovery blocks cleanup");
        Assert.Equal("retain after reboot", File.ReadAllText(Path.Combine(documents, "unsynced.txt")), "unsynced Documents survive");
    }

    private sealed class UnconfirmedLogonManager(TestSessionManager adapter) : IWindowsSessionManager
    {
        public bool Confirmed { get; set; }
        public Task<bool> IsWindowsLogonConfirmedAsync(CancellationToken token) => Task.FromResult(Confirmed);
        public void BindSession(string sessionId, string studentId) => adapter.BindSession(sessionId, studentId);
        public Task<string> PrepareProfileAsync(string studentId, CancellationToken token) => adapter.PrepareProfileAsync(studentId, token);
        public Task EndUserSessionAsync(string studentId, CancellationToken token) => adapter.EndUserSessionAsync(studentId, token);
        public Task CleanupProfileAsync(string studentId, CancellationToken token) => adapter.CleanupProfileAsync(studentId, token);
        public Task<bool> HasRecoveryStateAsync(string studentId, CancellationToken token) => adapter.HasRecoveryStateAsync(studentId, token);
    }

    private static void AgentPreparationRetry()
    {
        using var harness = Harness.Create();
        harness.Server.StartSession("student-a");
        var manager = new TransientFailureManager(new TestSessionManager(harness.ProfileRootDirectory)) { FailPreparation = true };
        var loop = new AgentLoop(harness.State, new AgentApiClient(harness.Server.CreateHttpClient()), manager);
        loop.TickOnceAsync(default).GetAwaiter().GetResult();
        Assert.Equal(SessionStateMachine.SyncPending, harness.State.SessionStatus, "preparation failure is visible as pending");
        Assert.True(!harness.State.ProfilePrepared, "partial preparation is not marked ready");
        RunUntil(loop, harness.State, () => harness.State.SessionStatus == SessionStateMachine.Active);
        Assert.Equal(0, harness.State.RetryOperations.Count, "restoration confirms the recovered preparation");
    }

    private static void AgentConflictRebase() => RunConflictRebase(restart: false, deleted: false, localDelete: false);
    private static void AgentConflictRebaseRestart() => RunConflictRebase(restart: true, deleted: false, localDelete: false);
    private static void AgentDeletedConflictRebase() => RunConflictRebase(restart: false, deleted: true, localDelete: false);
    private static void AgentEmptyConflictRebase() => RunConflictRebase(restart: false, deleted: false, localDelete: true);

    private static void RunConflictRebase(bool restart, bool deleted, bool localDelete)
    {
        using var harness = Harness.Create();
        harness.Server.PutStudentFile("student-a", "work.txt", "initial");
        var sessionId = harness.Server.StartSession("student-a");
        harness.RunUntil(() => harness.State.SessionStatus == SessionStateMachine.Active);
        var root = harness.State.DocumentsRoot!;
        if (localDelete) File.Delete(Path.Combine(root, "work.txt"));
        else File.WriteAllText(Path.Combine(root, "work.txt"), "unique student edit");
        if (deleted) harness.Server.DeleteStudentFile("student-a", "work.txt");
        else harness.Server.PutStudentFile("student-a", "work.txt", "browser version");
        harness.Server.RequestEnd();
        harness.Tick();
        Assert.Equal(SessionStateMachine.RecoveryRequired, harness.State.SessionStatus, "unresolved conflict blocks cleanup");
        harness.Server.ResolveConflictsKeepServer();
        if (!restart)
        {
            harness.Tick();
            Assert.True(harness.State.RebasePlan is not null && !harness.State.CleanupDone, "server rejects rebase before teacher authorization");
        }
        harness.Server.AuthorizeRecovery();
        var state = harness.State;
        var loop = harness.Loop;
        if (restart)
        {
            harness.Server.FailNext("rebase");
            harness.Tick();
            Assert.True(state.RebasePlan is not null, "interrupted rebase plan remains durable");
            state = StateJournal.Load(harness.StateDirectory);
            loop = new AgentLoop(state, new AgentApiClient(harness.Server.CreateHttpClient()), new TestSessionManager(harness.ProfileRootDirectory));
        }
        RunUntil(loop, state, () => state.SessionStatus == SessionStateMachine.Closed);
        Assert.True(!Directory.Exists(root), "cleanup occurs only after replacement confirmation");
        Assert.True(harness.Server.ConfirmedVersion("student-a") >= 2, "archived manifest is replaced even for an empty diff");
        if (deleted) Assert.True(!harness.Server.StudentHasFile("student-a", "work.txt"), "server tombstone is retained");
        else Assert.Equal("browser version", harness.Server.ReadStudentFile("student-a", "work.txt")!, "chosen server version is preserved");
        if (!localDelete)
        {
            var hash = ManifestEngine.HashBytes(System.Text.Encoding.UTF8.GetBytes("unique student edit"));
            Assert.Equal("unique student edit", harness.Server.ReadStudentFile("student-a", $"Conflicts/{sessionId}/{hash}.txt")!, "unique local bytes were uploaded as a conflict copy");
        }
    }

    private static void AgentCleanupRetry()
    {
        using var harness = Harness.Create();
        harness.Server.StartSession("student-a");
        var manager = new TransientFailureManager(new TestSessionManager(harness.ProfileRootDirectory)) { FailCleanup = true };
        var loop = new AgentLoop(harness.State, new AgentApiClient(harness.Server.CreateHttpClient()), manager);
        RunUntil(loop, harness.State, () => harness.State.SessionStatus == SessionStateMachine.Active);
        File.WriteAllText(Path.Combine(harness.State.DocumentsRoot!, "saved.txt"), "retained");
        harness.Server.RequestEnd();
        loop.TickOnceAsync(default).GetAwaiter().GetResult();
        Assert.Equal(SessionStateMachine.RecoveryRequired, harness.State.SessionStatus, "cleanup failure preserves recovery");
        Assert.True(harness.State.StorageConfirmed && Directory.Exists(harness.State.DocumentsRoot!), "receipt and local copy survive");
        RunUntil(loop, harness.State, () => harness.State.SessionStatus == SessionStateMachine.Closed);
        Assert.Equal("retained", harness.Server.ReadStudentFile("student-a", "saved.txt")!, "saved copy remains after idempotent retry");
    }

    private sealed class TransientFailureManager(TestSessionManager adapter) : IWindowsSessionManager
    {
        public bool FailPreparation { get; set; }
        public bool FailCleanup { get; set; }
        public bool IsTestAdapter => true;
        public void BindSession(string sessionId, string studentId) => adapter.BindSession(sessionId, studentId);
        public Task<string> PrepareProfileAsync(string studentId, CancellationToken token)
        {
            if (FailPreparation) { FailPreparation = false; throw new IOException("test preparation interrupted"); }
            return adapter.PrepareProfileAsync(studentId, token);
        }
        public Task EndUserSessionAsync(string studentId, CancellationToken token) => adapter.EndUserSessionAsync(studentId, token);
        public Task CleanupProfileAsync(string studentId, CancellationToken token)
        {
            if (FailCleanup) { FailCleanup = false; throw new IOException("test cleanup interrupted"); }
            return adapter.CleanupProfileAsync(studentId, token);
        }
        public Task<bool> HasRecoveryStateAsync(string studentId, CancellationToken token) => adapter.HasRecoveryStateAsync(studentId, token);
    }

    private sealed class Harness : IDisposable
    {
        private readonly string _baseDirectory;
        private readonly string _profileRootDirectory;
        private readonly TestSessionManager _manager;

        private Harness(string baseDirectory, FakeAgentServer server, AgentLoop loop, StateJournal state,
            TestSessionManager manager, string stateDirectory, string profileRootDirectory)
        {
            _baseDirectory = baseDirectory;
            Server = server;
            Loop = loop;
            State = state;
            _manager = manager;
            StateDirectory = stateDirectory;
            _profileRootDirectory = profileRootDirectory;
        }

        public FakeAgentServer Server { get; }
        public AgentLoop Loop { get; }
        public StateJournal State { get; }
        public string StateDirectory { get; }
        public string ProfileRootDirectory => _profileRootDirectory;

        public static Harness Create()
        {
            var baseDirectory = TemporaryDirectory();
            var stateDirectory = Path.Combine(baseDirectory, "state");
            Directory.CreateDirectory(stateDirectory);
            var profileRoot = Path.Combine(baseDirectory, "profiles");
            var server = new FakeAgentServer();
            var state = StateJournal.Load(stateDirectory);
            state.DeviceId = server.DeviceId;
            state.Credential = server.Credential;
            state.BaseUrl = "http://agent.test/";
            state.Save();
            var manager = new TestSessionManager(profileRoot);
            var loop = new AgentLoop(state, new AgentApiClient(server.CreateHttpClient()), manager,
                _ => Task.CompletedTask);
            return new Harness(baseDirectory, server, loop, state, manager, stateDirectory, profileRoot);
        }

        public string Documents(string studentId) =>
            Path.Combine(_manager.ProfileRoot(studentId), "Documents");

        public string ProfileRoot(string studentId) => _manager.ProfileRoot(studentId);

        public void Tick() => Loop.TickOnceAsync(CancellationToken.None).GetAwaiter().GetResult();

        public void RunUntil(Func<bool> predicate) => Program.RunUntil(Loop, State, predicate);

        public void Dispose()
        {
            try
            {
                if (Directory.Exists(_baseDirectory))
                {
                    Directory.Delete(_baseDirectory, recursive: true);
                }
            }
            catch (IOException)
            {
            }
        }
    }

    private static void WindowsSessionManagerRejectsWhenDisabled()
    {
        WindowsSessionManager.DangerousModeEnabled = false;
        var manager = new WindowsSessionManager();
        try
        {
            manager.PrepareProfileAsync("std-001", CancellationToken.None).GetAwaiter().GetResult();
            throw new Exception("Expected NotSupportedException");
        }
        catch (NotSupportedException)
        {
            // Expected
        }
    }

    private static void WindowsSessionManagerRequiresEnv()
    {
        Environment.SetEnvironmentVariable("PLATFORM_AGENT_DANGEROUS_MODE", null);
        WindowsSessionManager.DangerousModeEnabled = true;
        var manager = new WindowsSessionManager();
        try
        {
            manager.PrepareProfileAsync("std-001", CancellationToken.None).GetAwaiter().GetResult();
            throw new Exception("Expected NotSupportedException without environment variable");
        }
        catch (NotSupportedException)
        {
            // Expected
        }
        finally
        {
            WindowsSessionManager.DangerousModeEnabled = false;
        }
    }

    private static void WindowsSessionManagerFormatting()
    {
        var username = WindowsSessionManager.ResolveUsername("11111111-2222-3333-4444-555555555555");
        Assert.True(username.StartsWith("platform_"), "Username should start with prefix");
        Assert.True(username.Length <= 20, "Windows username must not exceed 20 characters");
        Assert.True(username.All(c => char.IsAsciiLetterOrDigit(c) || c == '_'), "Username must contain only safe characters");

        var path = WindowsSessionManager.ResolveProfileRoot("std-test-user");
        Assert.True(!path.Contains("std-test-user"), "Recovery path uses an opaque identity digest");
        Assert.True(WindowsSessionManager.ResolveUsername("11111111-2222-3333-4444-555555555555")
            != WindowsSessionManager.ResolveUsername("11111111-2222-3333-4444-666666666666"),
            "Matching identity prefixes must not reuse an account");
    }
}

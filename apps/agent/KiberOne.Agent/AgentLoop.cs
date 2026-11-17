using System.Text.Json;

namespace KiberOne.Agent;

/// <summary>
/// Drives the managed session lifecycle: receives one-shot commands, reports
/// strictly ordered state transitions, syncs Documents and survives restarts
/// via the persistent journal. Local documents are never deleted before the
/// server confirms the accepted manifest version.
/// </summary>
public sealed class AgentLoop
{
    private readonly StateJournal _state;
    private readonly SemaphoreSlim _gate;
    private readonly AgentApiClient _api;
    private readonly IWindowsSessionManager _sessions;
    private readonly Func<CancellationToken, Task> _wait;

    public AgentLoop(StateJournal state, AgentApiClient api, IWindowsSessionManager sessions,
        Func<CancellationToken, Task>? wait = null, SemaphoreSlim? gate = null)
    {
        _gate = gate ?? new SemaphoreSlim(1, 1);
        _state = state;
        _api = api;
        _sessions = sessions;
        _wait = wait ?? (cancellation => Task.Delay(TimeSpan.FromSeconds(5), cancellation));
    }

    public async Task RunAsync(CancellationToken cancellation)
    {
        while (!cancellation.IsCancellationRequested)
        {
            try
            {
                await TickOnceAsync(cancellation);
            }
            catch (OperationCanceledException)
            {
                break;
            }
            catch (JournalCorruptedException)
            {
                throw; // Never replace a damaged journal or delete documents silently.
            }
            catch (Exception failure)
            {
                try
                {
                    await _api.ReportEventAsync(_state.Credential!, "agent_error",
                        new { message = SafeMessage(failure) }, CancellationToken.None);
                }
                catch
                {
                    // Offline: keep the journal, retry on the next tick.
                }
            }
            await _wait(cancellation);
        }
    }

    /// <summary>Runs one deterministic lifecycle tick. Exposed for the local scenario harness.</summary>
    public async Task TickOnceAsync(CancellationToken cancellation)
    {
        await _gate.WaitAsync(cancellation);
        try { await TickCoreAsync(cancellation); }
        catch (Exception failure) when (failure is not OperationCanceledException and not JournalCorruptedException)
        {
            _state.ServerConnected = false;
            _state.LastError = new SessionError(SafeMessage(failure), SafeMessage(failure), DateTimeOffset.UtcNow);
            if (failure is AgentApiException { StatusCode: 401 }) _state.EnrollmentState = "revoked";
            _state.Save();
            throw;
        }
        finally { _gate.Release(); }
    }

    private async Task TickCoreAsync(CancellationToken cancellation)
    {
        if (string.IsNullOrEmpty(_state.Credential))
        {
            return; // Waiting for enrollment; the UI performs enrollment and saves the journal.
        }
        var serverSession = await _api.HeartbeatAsync(_state.Credential, cancellation, new
        {
            version = "kiberone-agent/1.1", testAdapter = _sessions.IsTestAdapter,
            machineReady = _state.SessionId is null && !_state.HasUnfinishedDocuments && _state.RetryOperations.Count == 0,
            hasUnfinishedDocuments = _state.HasUnfinishedDocuments || _state.RetryOperations.Count > 0,
            sessionId = _state.SessionId, progress = _state.Progress,
        });
        _state.ServerConnected = true;
        _state.EnrollmentState = "confirmed";
        _state.DeviceNumber = _api.DeviceNumber;
        _state.ClassroomName = _api.ClassroomName;
        var commands = await _api.PollCommandsAsync(_state.Credential, cancellation);

        foreach (var command in commands)
        {
            if (IsExpired(command))
            {
                _state.Record($"command.{command.Kind}", "blocked", "expired", _state.SessionId);
                _state.Save();
                continue;
            }
            var acknowledge = true;
            try
            {
                acknowledge = command.Kind switch
                {
                    "start_session" => await HandleStartSessionAsync(command, serverSession, cancellation),
                    "end_session" => await HandleEndSessionAsync(command, serverSession, cancellation),
                    _ => true,
                };
            }
            catch (Exception failure)
            {
                acknowledge = false;
                _state.Record($"command.{command.Kind}", "retry", SafeMessage(failure), _state.SessionId);
                _state.Save();
            }
            if (!acknowledge)
            {
                continue;
            }
            await _api.AcknowledgeAsync(_state.Credential, command.Id, cancellation);
            _state.Record($"command.{command.Kind}", "server_confirmed", command.Id, _state.SessionId);
            _state.Save();
        }

        if (serverSession is null)
        {
            if (_state.SessionStatus == SessionStateMachine.Authorized && !_state.ProfilePrepared
                && _state.DocumentsRoot is null)
            {
                _state.CompleteClosed(); // Assignment cancelled before any local resources were prepared.
                _state.Save();
                return;
            }
            if (_state.SessionId is not null && !SessionStateMachine.IsTerminal(_state.SessionStatus))
            {
                _state.Record("session.missing", "blocked", "server_reports_no_session", _state.SessionId);
                _state.SessionStatus = SessionStateMachine.RecoveryRequired;
                _state.Save();
            }
            return;
        }

        if (_state.SessionId != serverSession.Id)
        {
            if (_state.SessionId is not null
                && (!SessionStateMachine.IsTerminal(_state.SessionStatus)
                    || _state.SessionStatus == SessionStateMachine.Failed))
            {
                _state.Record("session.blocked", "blocked", "unfinished_previous_session", _state.SessionId);
                _state.Save();
                return;
            }
            _state.StartSession(serverSession.Id, serverSession.StudentId, serverSession.Status,
                serverSession.DesiredStatus);
            _state.Save();
        }
        else
        {
            _state.SessionStatus = serverSession.Status;
            _state.DesiredStatus = serverSession.DesiredStatus;
            _state.StudentId = serverSession.StudentId;
            _state.Save();
        }

        _sessions.BindSession(serverSession.Id, serverSession.StudentId);
        try { await ResumeAsync(serverSession, cancellation); }
        catch (Exception failure) when (failure is not OperationCanceledException and not JournalCorruptedException)
        {
            await FailAsync(serverSession, failure, cancellation);
        }
    }

    private async Task<bool> HandleStartSessionAsync(ApiCommand command, ApiSession? serverSession,
        CancellationToken cancellation)
    {
        if (!command.Payload.TryGetProperty("sessionId", out var sessionValue)
            || sessionValue.GetString() is not { } sessionId
            || !command.Payload.TryGetProperty("studentId", out var studentValue)
            || studentValue.GetString() is not { } studentId)
        {
            throw new AgentApiException(422, "invalid_start_session");
        }
        if (serverSession is null || serverSession.Id != sessionId)
        {
            _state.Record("command.start_session", "blocked", "stale_session", sessionId);
            _state.Save();
            return true; // Acknowledge a stale command; it no longer describes the device session.
        }
        if (!string.IsNullOrEmpty(_state.SessionId) && _state.SessionId != sessionId
            && (!SessionStateMachine.IsTerminal(_state.SessionStatus)
                || _state.SessionStatus == SessionStateMachine.Failed))
        {
            _state.Record("command.start_session", "blocked", "previous_session_unfinished", _state.SessionId);
            _state.Save();
            return false; // Never start the next student before recovery completes.
        }
        if (_state.SessionId != sessionId)
        {
            _state.StartSession(sessionId, studentId, serverSession.Status, serverSession.DesiredStatus);
            _state.Save();
        }
        if (serverSession.Status == SessionStateMachine.Authorized)
        {
            var report = await _api.ReportSessionStateAsync(_state.Credential!, sessionId,
                SessionStateMachine.Authorized, cancellation);
            _state.SessionStatus = report.Status;
            _state.Save();
        }
        _state.Record("command.start_session", "server_confirmed", null, sessionId);
        _state.Save();
        return true;
    }

    private Task<bool> HandleEndSessionAsync(ApiCommand command, ApiSession? serverSession,
        CancellationToken cancellation)
    {
        cancellation.ThrowIfCancellationRequested();
        if (serverSession is null)
        {
            _state.Record("command.end_session", "blocked", "no_active_session", _state.SessionId);
            _state.Save();
            return Task.FromResult(true);
        }
        var commandSession = command.Payload.TryGetProperty("sessionId", out var value) ? value.GetString() : null;
        if (commandSession != serverSession.Id)
        {
            _state.Record("command.end_session", "blocked", "stale_session", commandSession);
            _state.Save();
            return Task.FromResult(true);
        }
        if (serverSession.DesiredStatus != SessionStateMachine.Ending)
        {
            _state.Record("command.end_session", "blocked", "end_not_authorized", serverSession.Id);
            _state.Save();
            return Task.FromResult(true);
        }
        if (_state.SessionId != serverSession.Id)
        {
            _state.StartSession(serverSession.Id, serverSession.StudentId,
                serverSession.Status, serverSession.DesiredStatus);
        }
        _state.DesiredStatus = SessionStateMachine.Ending;
        _state.Record("command.end_session", "performed", null, serverSession.Id);
        _state.Save();
        return Task.FromResult(true);
    }

    private async Task ResumeAsync(ApiSession session, CancellationToken cancellation)
    {
        var status = session.Status;
        while (!SessionStateMachine.IsTerminal(status))
        {
            switch (status)
            {
                case SessionStateMachine.Authorized:
                    status = await ReportAsync(session, SessionStateMachine.Preparing, cancellation);
                    break;
                case SessionStateMachine.Preparing:
                    await PrepareProfileOnceAsync(session, cancellation);
                    status = await ReportAsync(session, SessionStateMachine.WindowsProfileReady, cancellation);
                    break;
                case SessionStateMachine.WindowsProfileReady:
                    status = await ReportAsync(session, SessionStateMachine.SyncingIn, cancellation);
                    break;
                case SessionStateMachine.SyncingIn:
                    try
                    {
                        if (!_state.DocumentsRestored)
                        {
                            await SyncInAsync(cancellation);
                            _state.DocumentsRestored = true;
                            _state.Save();
                        }
                        if (session.DesiredStatus == SessionStateMachine.Ending || _state.DesiredStatus == SessionStateMachine.Ending)
                        {
                            status = await ReportAsync(session, SessionStateMachine.Ending, cancellation);
                            break;
                        }
                        _state.WindowsLogonConfirmed = await _sessions.IsWindowsLogonConfirmedAsync(cancellation);
                        if (!_sessions.IsTestAdapter && !_state.WindowsLogonConfirmed)
                        {
                            _state.Progress = new SessionProgress("awaiting_windows_logon", 0, 1);
                            _state.Save();
                            await ReportProgressAsync(cancellation);
                            return;
                        }
                    }
                    catch (Exception failure)
                    {
                        await FailAsync(session, failure, cancellation);
                        return;
                    }
                    _state.Progress = new SessionProgress("active", 1, 1);
                    status = await ReportAsync(session, SessionStateMachine.Active, cancellation);
                    break;
                case SessionStateMachine.Active:
                    if (session.DesiredStatus != SessionStateMachine.Ending
                        && _state.DesiredStatus != SessionStateMachine.Ending)
                    {
                        // A durable journal is not proof that the interactive token
                        // survived a reboot or an out-of-band Windows sign-out.
                        if (!_sessions.IsTestAdapter)
                        {
                            _state.WindowsLogonConfirmed = await _sessions.IsWindowsLogonConfirmedAsync(cancellation);
                            if (!_state.WindowsLogonConfirmed)
                                throw new AgentIntegrityException("The managed Windows logon is no longer present; recovery is required.");
                        }
                        _state.SessionStatus = SessionStateMachine.Active;
                        _state.Save(); // ACTIVE only ends after an authorized end command.
                        return;
                    }
                    status = await ReportAsync(session, SessionStateMachine.Ending, cancellation);
                    break;
                case SessionStateMachine.Ending:
                    await EndUserSessionOnceAsync(session, cancellation);
                    status = await ReportAsync(session, SessionStateMachine.SyncingOut, cancellation);
                    break;
                case SessionStateMachine.SyncingOut:
                    try
                    {
                        await SyncOutAsync(cancellation);
                    }
                    catch (Exception failure)
                    {
                        await FailAsync(session, failure, cancellation);
                        return;
                    }
                    status = await ReportAsync(session, SessionStateMachine.StorageConfirmed, cancellation);
                    break;
                case SessionStateMachine.StorageConfirmed:
                    status = await ReportAsync(session, SessionStateMachine.Cleanup, cancellation);
                    break;
                case SessionStateMachine.Cleanup:
                    await CleanupOnceAsync(session, cancellation);
                    status = await ReportAsync(session, SessionStateMachine.Closed, cancellation);
                    break;
                case SessionStateMachine.SyncPending:
                    status = await ReportAsync(session, !_state.ProfilePrepared ? SessionStateMachine.Preparing
                        : !_state.UserSessionEnded ? SessionStateMachine.SyncingIn : SessionStateMachine.SyncingOut, cancellation);
                    break;
                case SessionStateMachine.RecoveryRequired:
                    if (session.DesiredStatus == SessionStateMachine.Ending || _state.DesiredStatus == SessionStateMachine.Ending)
                    {
                        status = await ReportAsync(session, _state.StorageConfirmed ? SessionStateMachine.Cleanup
                            : !_state.ProfilePrepared ? SessionStateMachine.Preparing
                            : !_state.UserSessionEnded ? SessionStateMachine.SyncingIn : SessionStateMachine.SyncingOut, cancellation);
                        break;
                    }
                    _state.Record("session.recovery_required", "blocked", null, session.Id);
                    _state.Save();
                    return;
                default:
                    _state.Record("session.unknown_state", "blocked", status, session.Id);
                    _state.Save();
                    return;
            }
        }
        CompleteSession(status);
    }

    private async Task PrepareProfileOnceAsync(ApiSession session, CancellationToken cancellation)
    {
        if (_state.ProfilePrepared && !string.IsNullOrEmpty(_state.DocumentsRoot)
            && Directory.Exists(_state.DocumentsRoot))
        {
            return;
        }
        var root = await _sessions.PrepareProfileAsync(session.StudentId, cancellation);
        if (string.IsNullOrWhiteSpace(root))
        {
            throw new IOException("The session manager did not return a Documents root.");
        }
        _state.DocumentsRoot = Path.GetFullPath(root);
        _state.ProfilePrepared = true;
        _state.Record("profile.prepared", "performed", null, session.Id);
        _state.Save();
    }

    private async Task EndUserSessionOnceAsync(ApiSession session, CancellationToken cancellation)
    {
        if (_state.UserSessionEnded)
        {
            return;
        }
        await _sessions.EndUserSessionAsync(session.StudentId, cancellation);
        _state.UserSessionEnded = true;
        _state.Record("session.user_session_ended", "performed", null, session.Id);
        _state.Save();
    }

    private async Task CleanupOnceAsync(ApiSession session, CancellationToken cancellation)
    {
        if (_state.CleanupDone)
        {
            return;
        }
        if (!_state.UserSessionEnded || !_state.StorageConfirmed || _state.PendingManifestVersion != 0)
            throw new AgentIntegrityException("Cleanup requires ended user session and confirmed manifest.");
        _state.ClearRetry($"session:{session.Id}"); // Retrying cleanup after a durable Storage receipt is idempotent.
        if (_state.RetryOperations.Count > 0) throw new AgentIntegrityException("Recovery must finish before cleanup.");
        _sessions.ConfirmStorage(session.Id);
        await _sessions.CleanupProfileAsync(session.StudentId, cancellation);
        _state.CleanupDone = true;
        _state.Record("profile.cleaned", "performed", null, session.Id);
        _state.Save();
    }

    private async Task<string> ReportAsync(ApiSession session, string next, CancellationToken cancellation)
    {
        var report = await _api.ReportSessionStateAsync(_state.Credential!, session.Id, next, cancellation,
            Evidence(), _state.Progress);
        _state.SessionStatus = report.Status;
        _state.Record($"state.{report.Status}", "server_confirmed", null, session.Id);
        _state.Save();
        return report.Status;
    }

    private async Task FailAsync(ApiSession session, Exception failure, CancellationToken cancellation)
    {
        _state.LastError = new SessionError(SafeMessage(failure), SafeMessage(failure), DateTimeOffset.UtcNow);
        _state.Record("session.sync_error", "retry", SafeMessage(failure), session.Id);
        _state.RequireRetry($"session:{session.Id}");
        _state.Save();
        try
        {
            var next = failure is AgentConflictException or AgentIntegrityException || _state.StorageConfirmed
                ? SessionStateMachine.RecoveryRequired
                : SessionStateMachine.SyncPending;
            var report = await _api.ReportSessionStateAsync(_state.Credential!, session.Id, next, cancellation,
                Evidence(), _state.Progress, new { code = SafeMessage(failure), message = SafeMessage(failure) });
            _state.SessionStatus = report.Status;
            _state.Record($"state.{report.Status}", "server_confirmed", SafeMessage(failure), session.Id);
        }
        catch
        {
            // The server is unreachable; the next heartbeat resumes the same state and retries.
        }
        _state.Save();
    }

    private object Evidence() => new
    {
        profilePrepared = _state.ProfilePrepared, windowsLogonConfirmed = _state.WindowsLogonConfirmed,
        testAdapter = _sessions.IsTestAdapter, sessionEnded = _state.UserSessionEnded,
        storageConfirmed = _state.StorageConfirmed, cleanupDone = _state.CleanupDone,
    };

    private async Task ReportProgressAsync(CancellationToken cancellation)
    {
        await _api.ReportSessionStateAsync(_state.Credential!, _state.SessionId!, _state.SessionStatus,
            cancellation, Evidence(), _state.Progress);
    }

    private async Task SyncInAsync(CancellationToken cancellation)
    {
        var root = _state.DocumentsRoot
            ?? throw new AgentIntegrityException("Documents root is not prepared.");
        Directory.CreateDirectory(root);
        var server = await _api.GetManifestAsync(_state.Credential!, cancellation);
        if (server.Entries.Length > ManifestEntry.MaxEntryCount)
        {
            throw new AgentIntegrityException("The server manifest exceeds the supported entry count.");
        }
        var serverPaths = new HashSet<string>(server.Entries.Select(entry => ManifestEngine.Normalize(entry.Path)),
            StringComparer.OrdinalIgnoreCase);
        foreach (var previous in _state.ConfirmedManifest)
        {
            if (serverPaths.Contains(previous.Path))
            {
                continue;
            }
            var stalePath = ManifestEngine.ResolvePath(root, previous.Path);
            if (File.Exists(stalePath)
                && ManifestEngine.ContentMatches(stalePath, previous.Sha256, previous.Size))
            {
                File.Delete(stalePath);
                _state.Record("sync_in.delete", "server_confirmed", previous.Path, _state.SessionId);
            }
        }
        _state.Progress = new SessionProgress("sync_in", 0, server.Entries.Length);
        await ReportProgressAsync(cancellation);
        foreach (var entry in server.Entries)
        {
            ManifestEngine.ValidateRelative(entry.Path);
            if (entry.Size < 0 || entry.Size > ManifestEntry.MaxEntrySize)
            {
                throw new AgentIntegrityException("Server manifest contains an unsupported file size.");
            }
            var localPath = ManifestEngine.ResolvePath(root, entry.Path);
            if (File.Exists(localPath) && ManifestEngine.ContentMatches(localPath, entry.Sha256, entry.Size))
            {
                _state.Progress = _state.Progress with { Completed = _state.Progress.Completed + 1 };
                continue;
            }
            var ticket = await _api.TicketForDownloadAsync(_state.Credential!, entry.Path, entry.Sha256,
                entry.Size, cancellation);
            var content = await _api.DownloadAsync(_state.Credential!, ticket, cancellation);
            ManifestEngine.PlaceAtomic(root, entry.Path, content);
            _state.Progress = _state.Progress with { Completed = _state.Progress.Completed + 1 };
            _state.Record("sync_in.file", "server_confirmed", entry.Path, _state.SessionId);
            await ReportProgressAsync(cancellation);
        }
        var verified = ManifestEngine.Build(root);
        foreach (var entry in server.Entries)
        {
            var localPath = ManifestEngine.ResolvePath(root, entry.Path);
            if (!ManifestEngine.ContentMatches(localPath, entry.Sha256, entry.Size))
            {
                throw new AgentIntegrityException("Documents restoration did not converge.");
            }
        }
        _state.LastManifestVersion = server.Version;
        _state.ConfirmedManifest = ManifestEngine.Confirmed(server.Entries).ToList();
        _state.PendingManifestVersion = 0;
        _state.PendingManifest = new List<ManifestEntry>();
        _state.ClearRetry($"session:{_state.SessionId}");
        _state.LastError = null;
        _state.Record("sync_in", "server_confirmed",
            $"{server.Version}:{verified.Length}", _state.SessionId);
        _state.Save();
    }

    private async Task SyncOutAsync(CancellationToken cancellation)
    {
        var root = _state.DocumentsRoot
            ?? throw new AgentIntegrityException("Documents root is not prepared.");
        if (!Directory.Exists(root)) throw new AgentIntegrityException("Documents root is missing; refusing to infer deletions.");
        if (_state.RebasePlan is not null) await ResumeManifestRebaseAsync(root, cancellation);
        var current = ManifestEngine.Build(root);
        ManifestEntry[] entries;
        long version;
        if (_state.PendingManifestVersion > 0)
        {
            entries = _state.PendingManifest.ToArray();
            version = _state.PendingManifestVersion;
            var recomputed = ManifestEngine.Diff(_state.ConfirmedManifest.ToArray(), current);
            if (!ManifestEngine.SameEntries(recomputed, entries))
            {
                throw new AgentIntegrityException("Documents changed while a manifest was unconfirmed.");
            }
        }
        else
        {
            entries = ManifestEngine.Diff(_state.ConfirmedManifest.ToArray(), current);
            if (entries.Length == 0 && _state.NextManifestVersion == 0)
            {
                var stable = await _api.ConfirmSyncAsync(_state.Credential!, _state.LastManifestVersion,
                    current, cancellation);
                if (!stable.Confirmed)
                {
                    throw new AgentIntegrityException("The server did not confirm the unchanged manifest.");
                }
                _state.MarkConfirmed(stable.Version, current);
                _state.StorageConfirmed = true;
                _state.ClearRetry($"session:{_state.SessionId}");
                _state.Record("sync_out.confirmed", "server_confirmed",
                    $"{stable.Version}:0", _state.SessionId);
                _state.Save();
                return;
            }
            version = Math.Max(_state.LastManifestVersion + 1, _state.NextManifestVersion);
            _state.MarkPending(version, entries);
            _state.Record("manifest.submitted", "performed", version.ToString(), _state.SessionId);
            _state.Save();
        }

        var merge = await _api.PostManifestAsync(_state.Credential!, version, entries, cancellation);
        if (merge.Conflicts.Length > 0)
        {
            if (merge.Conflicts.All(conflict => conflict.Resolution == "keep_server"))
            {
                await BeginManifestRebaseAsync(root, version, merge.Conflicts, cancellation);
                await ResumeManifestRebaseAsync(root, cancellation);
                await SyncOutAsync(cancellation);
                return;
            }
            var paths = merge.Conflicts.Select(conflict => conflict.Path).ToArray();
            _state.Record("sync_out.conflict", "blocked", string.Join(',', paths), _state.SessionId);
            _state.Save();
            throw new AgentConflictException(paths);
        }
        _state.Progress = new SessionProgress("sync_out", 0, merge.Uploads.Length);
        await ReportProgressAsync(cancellation);
        foreach (var upload in merge.Uploads)
        {
            var path = ManifestEngine.ResolvePath(root, upload.Path);
            var content = await File.ReadAllBytesAsync(path, cancellation);
            if (content.LongLength != upload.ExpectedSize
                || (upload.ExpectedSha256 is not null
                    && !string.Equals(ManifestEngine.HashBytes(content), upload.ExpectedSha256,
                        StringComparison.OrdinalIgnoreCase)))
            {
                throw new AgentIntegrityException("A local file changed after the manifest was submitted.");
            }
            await _api.UploadAsync(_state.Credential!, upload, content, cancellation);
            _state.Progress = _state.Progress with { Completed = _state.Progress.Completed + 1 };
            _state.Record("upload.confirmed", "server_confirmed", upload.Path, _state.SessionId);
            _state.Save();
            await ReportProgressAsync(cancellation);
        }
        var confirmation = await _api.ConfirmSyncAsync(_state.Credential!, version, current, cancellation);
        if (!confirmation.Confirmed)
        {
            throw new AgentIntegrityException("The server did not confirm the uploaded manifest.");
        }
        _state.MarkConfirmed(confirmation.Version, current);
        _state.StorageConfirmed = true;
        _state.ClearRetry($"session:{_state.SessionId}");
        _state.LastError = null;
        _state.Record("sync_out.confirmed", "server_confirmed",
            $"{confirmation.Version}:{confirmation.VerifiedEntries}", _state.SessionId);
        _state.Save();
    }

    private async Task BeginManifestRebaseAsync(string root, long version, ConflictEntry[] conflicts, CancellationToken cancellation)
    {
        if (!_state.UserSessionEnded || _state.SessionId is null)
            throw new AgentIntegrityException("Conflict recovery requires an ended user session.");
        var server = await _api.GetManifestAsync(_state.Credential!, cancellation);
        var live = server.Entries.ToDictionary(entry => entry.Path, StringComparer.OrdinalIgnoreCase);
        var baseline = _state.ConfirmedManifest.ToDictionary(entry => entry.Path, StringComparer.OrdinalIgnoreCase);
        var targets = new Dictionary<string, ManifestRebaseTarget>(StringComparer.OrdinalIgnoreCase);
        foreach (var conflict in conflicts)
        {
            var path = ManifestEngine.ResolvePath(root, conflict.Path);
            var original = File.Exists(path) ? new FileInfo(path) : null;
            var sha = original is null ? null : ManifestEngine.HashFile(path);
            var extension = Path.GetExtension(conflict.Path);
            if (extension.Length == 0 || extension.Length > 16 || extension.EndsWith('.')
                || extension.Any(character => !char.IsAsciiLetterOrDigit(character) && character != '.')) extension = ".bin";
            var copy = sha is null ? null : $"Conflicts/{_state.SessionId}/{sha}{extension}";
            live.TryGetValue(conflict.Path, out var remote);
            targets.Add(conflict.Path, new(conflict.Path, sha, original?.Length ?? 0, copy, remote));
        }
        // Adopt unrelated server edits only when this session has no local edit at that path.
        // Unique local changes are kept for the next optimistic manifest instead.
        foreach (var remote in server.Entries)
        {
            if (targets.ContainsKey(remote.Path)) continue;
            var local = ManifestEngine.ResolvePath(root, remote.Path);
            baseline.TryGetValue(remote.Path, out var before);
            if (before is null && !File.Exists(local)) targets.Add(remote.Path, new(remote.Path, null, 0, null, remote));
            else if (before is not null && ManifestEngine.ContentMatches(local, before.Sha256, before.Size)
                && !ManifestEngine.ContentMatches(local, remote.Sha256, remote.Size))
                targets.Add(remote.Path, new(remote.Path, before.Sha256, before.Size, null, remote));
        }
        foreach (var before in _state.ConfirmedManifest)
            if (!live.ContainsKey(before.Path) && !targets.ContainsKey(before.Path)
                && ManifestEngine.ContentMatches(ManifestEngine.ResolvePath(root, before.Path), before.Sha256, before.Size))
                targets.Add(before.Path, new(before.Path, before.Sha256, before.Size, null, null));
        _state.RebasePlan = new(version, targets.Values.ToArray());
        _state.Record("manifest.rebase_planned", "performed", version.ToString(), _state.SessionId);
        _state.Save(); // Persist the plan before copying or replacing any file.
    }

    private async Task ResumeManifestRebaseAsync(string root, CancellationToken cancellation)
    {
        var plan = _state.RebasePlan ?? throw new AgentIntegrityException("Missing conflict recovery plan.");
        if (!_state.UserSessionEnded) throw new AgentIntegrityException("Conflict recovery cannot run while the student session is active.");
        var server = await _api.GetManifestAsync(_state.Credential!, cancellation);
        var live = server.Entries.ToDictionary(entry => entry.Path, StringComparer.OrdinalIgnoreCase);
        var updated = new List<ManifestRebaseTarget>();
        foreach (var target in plan.Targets)
        {
            var path = ManifestEngine.ResolvePath(root, target.Path);
            live.TryGetValue(target.Path, out var remote);
            if (File.Exists(path) && !(target.OriginalSha256 is not null && ManifestEngine.ContentMatches(path, target.OriginalSha256, target.OriginalSize))
                && !(target.Server is not null && ManifestEngine.ContentMatches(path, target.Server.Sha256, target.Server.Size))
                && !(target.PreviousSha256 is not null && ManifestEngine.ContentMatches(path, target.PreviousSha256, target.PreviousSize))
                && !(remote is not null && ManifestEngine.ContentMatches(path, remote.Sha256, remote.Size)))
                throw new AgentIntegrityException("A file changed outside the durable conflict recovery plan.");
            if (target.ConflictCopyPath is { } copyPath)
            {
                var backup = ManifestEngine.ResolvePath(root, copyPath);
                if (!File.Exists(backup))
                {
                    if (!ManifestEngine.ContentMatches(path, target.OriginalSha256!, target.OriginalSize))
                        throw new AgentIntegrityException("The unique conflict copy is missing.");
                    ManifestEngine.PlaceAtomic(root, copyPath, await File.ReadAllBytesAsync(path, cancellation));
                }
                if (!ManifestEngine.ContentMatches(backup, target.OriginalSha256!, target.OriginalSize))
                    throw new AgentIntegrityException("The preserved conflict copy failed verification.");
            }
            updated.Add(target with { Server = remote,
                PreviousSha256 = File.Exists(path) ? ManifestEngine.HashFile(path) : null,
                PreviousSize = File.Exists(path) ? new FileInfo(path).Length : 0 });
        }
        _state.RebasePlan = plan = plan with { Targets = updated.ToArray() };
        _state.Save();
        foreach (var target in plan.Targets)
        {
            var path = ManifestEngine.ResolvePath(root, target.Path);
            if (target.Server is { } remote)
            {
                if (ManifestEngine.ContentMatches(path, remote.Sha256, remote.Size)) continue;
                var ticket = await _api.TicketForDownloadAsync(_state.Credential!, remote.Path, remote.Sha256, remote.Size, cancellation);
                ManifestEngine.PlaceAtomic(root, remote.Path, await _api.DownloadAsync(_state.Credential!, ticket, cancellation));
            }
            else if (File.Exists(path)) File.Delete(path); // Any unique bytes are already in the verified conflict copy.
        }
        var receipt = await _api.RebaseManifestAsync(_state.Credential!, plan.Version, cancellation);
        var baseline = _state.ConfirmedManifest.ToDictionary(entry => entry.Path, StringComparer.OrdinalIgnoreCase);
        foreach (var target in plan.Targets)
        {
            baseline.Remove(target.Path);
            if (target.Server is { } remote)
                baseline.Add(remote.Path, new(remote.Path, ManifestOperation.Add, remote.Sha256, remote.Size, Version: remote.Version));
        }
        _state.ConfirmedManifest = baseline.Values.ToList();
        _state.PendingManifestVersion = 0;
        _state.PendingManifest = new();
        _state.NextManifestVersion = receipt.NextVersion;
        _state.RebasePlan = null;
        _state.ClearRetry($"sync_out:{plan.Version}");
        _state.Record("manifest.rebased", "server_confirmed", receipt.NextVersion.ToString(), _state.SessionId);
        _state.Save(); // Conflict copies now participate in the next manifest; cleanup still requires its confirmation.
    }

    private void CompleteSession(string status)
    {
        if (status == SessionStateMachine.Closed)
        {
            _state.Record("session.closed", "server_confirmed", null, _state.SessionId);
            _state.CompleteClosed();
            _state.Save();
            return;
        }
        _state.SessionStatus = status;
        _state.Record("session.failed", "blocked", "recovery_data_preserved", _state.SessionId);
        _state.Save(); // FAILED keeps DocumentsRoot and the confirmed manifest for recovery.
    }

    private static bool IsExpired(ApiCommand command) =>
        !DateTimeOffset.TryParse(command.ExpiresAt, out var expiresAt) || expiresAt <= DateTimeOffset.UtcNow;

    private static bool IsFatal(Exception failure) =>
        failure is AgentConflictException or AgentIntegrityException;

    private static string SafeMessage(Exception failure) => failure switch
    {
        AgentApiException api => $"platform_rejected:{api.StatusCode}:{api.Reason}",
        AgentTransportException => "network_error",
        AgentIntegrityException => "integrity_error",
        AgentConflictException => "version_conflict",
        IOException => "local_io_error",
        UnauthorizedAccessException => "local_access_error",
        _ => failure.GetType().Name,
    };
}

namespace KiberOne.Agent;

public sealed record AgentLogonRequest(int Version, string RequestId, string SessionId);
public sealed record AgentLogonGrant(int Version, string RequestId, string SessionId, string Sid,
    DateTimeOffset ExpiresAt, byte[] ProtectedCredential);

/// <summary>
/// Executable contract for a future Credential Provider host. Only the SYSTEM-only
/// service endpoint can issue a short-lived, machine-protected credential envelope.
/// Issuance does not confirm logon: the loop independently checks the WTS token SID.
/// </summary>
public sealed class AgentLogonBroker(StateJournal state, WindowsSessionManager sessions, SemaphoreSlim gate)
{
    public async Task<AgentLogonGrant> AuthorizeAsync(AgentLogonRequest request, AgentIpcPeer peer,
        CancellationToken cancellation)
    {
        if (!peer.IsLocalSystem || peer.Sid != "S-1-5-18") throw new UnauthorizedAccessException();
        if (request.Version != 1 || !Guid.TryParse(request.RequestId, out _) || request.SessionId != state.SessionId)
            throw new AgentIntegrityException("Logon authorization does not match the assigned session.");
        await gate.WaitAsync(cancellation);
        try
        {
            if (request.SessionId != state.SessionId || state.SessionStatus != SessionStateMachine.SyncingIn
                || !state.ProfilePrepared || !state.DocumentsRestored || state.WindowsLogonConfirmed
                || state.DesiredStatus == SessionStateMachine.Ending || state.RetryOperations.Count != 0
                || state.EnrollmentState != "confirmed" || !state.ServerConnected)
                throw new AgentIntegrityException("The assigned session is not ready for Windows logon.");
            if (state.LogonGrantExpiresAt > DateTimeOffset.UtcNow)
                throw new AgentIntegrityException("An outstanding logon grant already exists.");
            var expiresAt = DateTimeOffset.UtcNow.AddSeconds(30);
            var nonce = Guid.NewGuid().ToString();
            var envelope = sessions.CreateLogonEnvelope(request.SessionId, nonce, expiresAt);
            state.LogonGrantExpiresAt = expiresAt;
            state.Record("logon.authorized", "performed", nonce, request.SessionId);
            state.Save();
            return new(1, request.RequestId, request.SessionId, sessions.ManagedSid!, expiresAt, envelope);
        }
        finally { gate.Release(); }
    }
}

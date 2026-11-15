using System.Text.Json;

namespace Platform.Agent;

public sealed record AgentIpcRequest(int Version, string RequestId, string Command, string? Code = null, string? SessionId = null);
public sealed record AgentIpcPeer(string Sid, bool IsAdministrator, bool IsLocalSystem);
public sealed record SessionProgress(string Stage, int Completed, int Total);
public sealed record SessionError(string Code, string Message, DateTimeOffset At);
public sealed record AgentUiStatus(string? DeviceId, string? DeviceNumber, string? ClassroomName, string EnrollmentState,
    string SessionStatus, string? SessionId, string? StudentId, bool ServerConnected, bool RecoveryPending,
    bool TestAdapter, bool CanEnroll, bool CanEndSession, SessionProgress? Progress, SessionError? LastError);
public sealed record AgentIpcResponse(int Version, string RequestId, bool Ok, string? Error, AgentUiStatus? State);

/// <summary>The service supplies the OS-authenticated pipe peer; identity is never taken from JSON.</summary>
public sealed class AgentIpcHandler
{
    public static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        UnmappedMemberHandling = System.Text.Json.Serialization.JsonUnmappedMemberHandling.Disallow,
        MaxDepth = 8,
    };
    private readonly StateJournal _state;
    private readonly AgentApiClient _api;
    private readonly IWindowsSessionManager _sessions;
    private readonly SemaphoreSlim _gate;
    public AgentIpcHandler(StateJournal state, AgentApiClient api, IWindowsSessionManager sessions, SemaphoreSlim gate)
        => (_state, _api, _sessions, _gate) = (state, api, sessions, gate);

    public async Task<AgentIpcResponse> HandleAsync(AgentIpcRequest request, AgentIpcPeer peer, CancellationToken cancellation)
    {
        if (request.Version != 1 || !Guid.TryParse(request.RequestId, out _) || string.IsNullOrEmpty(peer.Sid))
            return Reply(request, peer, "invalid_request");
        if (request.Command is "status" or "ping" or "progress" or "error" or "recovery")
            return Reply(request, peer);
        if (request.Command is not ("enroll" or "confirm_enrollment" or "end_session"))
            return Reply(request, peer, "unknown_command");
        var administrator = peer.IsAdministrator || peer.IsLocalSystem;
        if (request.Command is "enroll" or "confirm_enrollment" && !administrator)
            return Reply(request, peer, "forbidden");
        if (!await _gate.WaitAsync(TimeSpan.FromSeconds(2), cancellation)) return Reply(request, peer, "busy");
        try
        {
            if (request.Command == "enroll")
            {
                if (_state.Credential is not null || _state.SessionId is not null || _state.HasUnfinishedDocuments
                    || _state.RetryOperations.Count != 0) return Reply(request, peer, "enrollment_blocked");
                if (request.Code is not { Length: > 0 and <= 64 } code || code.Any(char.IsControl))
                    return Reply(request, peer, "invalid_code");
                var enrolled = await _api.EnrollAsync(code.Trim(), cancellation);
                _state.DeviceId = enrolled.DeviceId;
                _state.CredentialId = enrolled.CredentialId;
                _state.Credential = enrolled.Credential;
                _state.EnrollmentState = "confirmed"; // The enroll API atomically confirms the one-time code.
                _state.Record("device.enrolled", "server_confirmed");
                _state.Save();
            }
            else if (request.Command == "confirm_enrollment")
            {
                if (_state.Credential is null) return Reply(request, peer, "not_enrolled");
                await _api.HeartbeatAsync(_state.Credential, cancellation, new
                {
                    version = "platform-agent/1.1", testAdapter = _sessions.IsTestAdapter,
                    machineReady = _state.SessionId is null && !_state.HasUnfinishedDocuments && _state.RetryOperations.Count == 0,
                    hasUnfinishedDocuments = _state.HasUnfinishedDocuments || _state.RetryOperations.Count > 0,
                });
                _state.EnrollmentState = "confirmed";
                _state.ServerConnected = true;
                _state.Save();
            }
            else
            {
                if (_state.SessionId is null || request.SessionId != _state.SessionId)
                    return Reply(request, peer, "session_mismatch");
                if (!administrator && (string.IsNullOrEmpty(_sessions.ManagedSid) || _sessions.ManagedSid != peer.Sid))
                    return Reply(request, peer, "forbidden");
                if (_state.SessionStatus != SessionStateMachine.Active || _state.Credential is null)
                    return Reply(request, peer, "session_not_active");
                await _api.RequestEndSessionAsync(_state.Credential, _state.SessionId, cancellation);
                _state.DesiredStatus = SessionStateMachine.Ending;
                _state.Record("session.local_end", "server_confirmed", null, _state.SessionId);
                _state.Save();
            }
            return Reply(request, peer);
        }
        catch (AgentApiException failure)
        {
            if (failure.StatusCode == 401) _state.EnrollmentState = "revoked";
            return Reply(request, peer, failure.Reason);
        }
        catch (Exception failure) when (failure is AgentTransportException or IOException or UnauthorizedAccessException)
        {
            return Reply(request, peer, "service_operation_failed");
        }
        finally { _gate.Release(); }
    }

    private AgentIpcResponse Reply(AgentIpcRequest request, AgentIpcPeer peer, string? error = null)
    {
        var privileged = peer.IsAdministrator || peer.IsLocalSystem;
        var ownsSession = privileged || _sessions.ManagedSid == peer.Sid;
        var recovery = _state.RetryOperations.Count > 0 || _state.SessionStatus is "RECOVERY_REQUIRED" or "SYNC_PENDING" or "FAILED";
        return new(1, request.RequestId, error is null, error, new AgentUiStatus(_state.DeviceId,
            _state.DeviceNumber, _state.ClassroomName, _state.EnrollmentState, _state.SessionStatus,
            ownsSession ? _state.SessionId : null, ownsSession ? _state.StudentId : null, _state.ServerConnected,
            recovery, _sessions.IsTestAdapter, privileged && _state.Credential is null && _state.SessionId is null
                && !_state.HasUnfinishedDocuments && _state.RetryOperations.Count == 0 && !recovery,
            ownsSession && _state.SessionId is not null && _state.SessionStatus == SessionStateMachine.Active,
            _state.Progress, ownsSession ? _state.LastError : null));
    }
}

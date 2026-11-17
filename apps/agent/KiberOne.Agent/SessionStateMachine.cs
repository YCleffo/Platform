namespace KiberOne.Agent;

/// <summary>
/// Server-side session lifecycle mirrored locally. Transitions are validated
/// twice: by the platform server and by this local copy so that the agent
/// never reports a state that the server would reject.
/// </summary>
public static class SessionStateMachine
{
    public const string Authorized = "AUTHORIZED";
    public const string Preparing = "PREPARING";
    public const string WindowsProfileReady = "WINDOWS_PROFILE_READY";
    public const string SyncingIn = "SYNCING_IN";
    public const string Active = "ACTIVE";
    public const string Ending = "ENDING";
    public const string SyncingOut = "SYNCING_OUT";
    public const string StorageConfirmed = "STORAGE_CONFIRMED";
    public const string Cleanup = "CLEANUP";
    public const string Closed = "CLOSED";
    public const string SyncPending = "SYNC_PENDING";
    public const string RecoveryRequired = "RECOVERY_REQUIRED";
    public const string Failed = "FAILED";

    private static readonly IReadOnlyDictionary<string, string[]> Allowed = new Dictionary<string, string[]>
    {
        [Authorized] = new[] { Preparing, SyncPending, Failed, RecoveryRequired },
        [Preparing] = new[] { WindowsProfileReady, SyncPending, Failed, RecoveryRequired },
        [WindowsProfileReady] = new[] { SyncingIn, SyncPending, Failed, RecoveryRequired },
        [SyncingIn] = new[] { Active, Ending, SyncPending, Failed, RecoveryRequired },
        [Active] = new[] { Ending, SyncPending, Failed, RecoveryRequired },
        [Ending] = new[] { SyncingOut, SyncPending, Failed, RecoveryRequired },
        [SyncingOut] = new[] { StorageConfirmed, SyncPending, Failed, RecoveryRequired },
        [StorageConfirmed] = new[] { Cleanup, SyncPending, Failed, RecoveryRequired },
        [Cleanup] = new[] { Closed, SyncPending, Failed, RecoveryRequired },
        [Closed] = Array.Empty<string>(),
        [SyncPending] = new[] { Preparing, SyncingIn, SyncingOut, Active, RecoveryRequired, Failed },
        [RecoveryRequired] = new[] { Preparing, SyncingIn, SyncingOut, Cleanup, Closed, Failed },
        [Failed] = new[] { RecoveryRequired },
    };

    public static bool IsKnown(string status) => Allowed.ContainsKey(status);

    public static bool IsTerminal(string status) => status is Closed or Failed;

    public static bool BlocksNextSession(string status) => status is SyncPending or RecoveryRequired;

    public static bool Allows(string from, string to) =>
        from == to || (Allowed.TryGetValue(from, out var next) && next.Contains(to));

    public static IEnumerable<string> KnownStates => Allowed.Keys;
}

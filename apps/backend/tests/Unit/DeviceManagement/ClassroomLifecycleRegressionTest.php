<?php

declare(strict_types=1);

namespace App\Tests\Unit\DeviceManagement;

use App\DeviceManagement\Application\DeviceAvailableActions;
use App\DeviceManagement\Application\DeviceError;
use App\DeviceManagement\Application\SessionEvidence;
use App\DeviceManagement\Application\SessionTransitions;
use PHPUnit\Framework\TestCase;

final class ClassroomLifecycleRegressionTest extends TestCase
{
    public function testPreparedProfileAloneDoesNotPermitActiveWindowsSession(): void
    {
        SessionEvidence::assertTransition('WINDOWS_PROFILE_READY', ['profilePrepared' => true]);
        $this->rejects('logon_not_confirmed', fn() => SessionEvidence::assertTransition('ACTIVE', ['profilePrepared' => true]));
        $this->rejects('logon_not_confirmed', fn() => SessionEvidence::assertTransition('ACTIVE', ['windowsLogonConfirmed' => true]));
        SessionEvidence::assertTransition('ACTIVE', ['profilePrepared' => true, 'windowsLogonConfirmed' => true]);
    }

    public function testExplicitTestAdapterMayRunWithoutClaimingNativeLogon(): void
    {
        $evidence = SessionEvidence::validate(['evidence' => ['profilePrepared' => true, 'testAdapter' => true, 'windowsLogonConfirmed' => false]]);
        self::assertFalse($evidence['windowsLogonConfirmed']);
        SessionEvidence::assertTransition('ACTIVE', $evidence);
    }

    public function testCleanupAndClosureCannotIgnoreUnsavedDocumentsOrRunningSession(): void
    {
        foreach (['STORAGE_CONFIRMED', 'CLEANUP', 'CLOSED'] as $state) {
            $this->rejects('storage_not_confirmed', fn() => SessionEvidence::assertTransition($state, ['sessionEnded' => true, 'cleanupDone' => true]));
            $this->rejects('storage_not_confirmed', fn() => SessionEvidence::assertTransition($state, ['storageConfirmed' => true, 'cleanupDone' => true]));
        }
        $this->rejects('cleanup_not_confirmed', fn() => SessionEvidence::assertTransition('CLOSED', ['sessionEnded' => true, 'storageConfirmed' => true]));
        SessionEvidence::assertTransition('CLOSED', ['sessionEnded' => true, 'storageConfirmed' => true, 'cleanupDone' => true]);
    }

    public function testUntrustedEvidenceMustUseRealBooleansAndKnownKeys(): void
    {
        foreach ([['profilePrepared' => 'true'], ['storageConfirmed' => 1], ['cleanupDone' => 'false'], ['arbitraryCommand' => true]] as $evidence) {
            $this->rejects('invalid_evidence', fn() => SessionEvidence::validate(['evidence' => $evidence]));
        }
    }

    public function testInvalidProgressCannotClaimMoreSavedFilesThanTotal(): void
    {
        foreach ([['stage' => 'sync_out', 'completed' => 2, 'total' => 1], ['stage' => 'sync_out', 'completed' => -1, 'total' => 5], ['stage' => 'sync_out', 'completed' => '1', 'total' => 5]] as $progress) {
            $this->rejects('invalid_progress', fn() => SessionEvidence::validate(['progress' => $progress]));
        }
        self::assertSame(['stage' => 'sync_out', 'completed' => 3, 'total' => 5], SessionEvidence::validate(['progress' => ['stage' => 'sync_out', 'completed' => 3, 'total' => 5]])['progress']);
    }

    public function testRecoveryStatesCannotAssignAnotherStudent(): void
    {
        foreach (['SYNC_PENDING', 'RECOVERY_REQUIRED', 'FAILED'] as $status) {
            foreach ([false, true] as $online) {
                $actions = DeviceAvailableActions::forDevice($this->device(), ['status' => $status], $online);
                self::assertNotContains('assign', $actions);
                self::assertNotContains('edit', $actions, 'Moving a device during recovery is unsafe.');
                self::assertNotContains('revoke', $actions, 'Revocation must not abandon recovery documents.');
                self::assertSame($online, in_array('retry_recovery', $actions, true));
            }
        }
    }

    public function testAssignmentRequiresConfirmedOnlineAvailableUnarchivedDevice(): void
    {
        self::assertContains('assign', DeviceAvailableActions::forDevice($this->device(), null, true));
        self::assertNotContains('assign', DeviceAvailableActions::forDevice($this->device(), null, false));
        self::assertNotContains('assign', DeviceAvailableActions::forDevice($this->device(), null, true, true));
        self::assertNotContains('assign', DeviceAvailableActions::forDevice($this->device('pending'), null, true));
        self::assertNotContains('assign', DeviceAvailableActions::forDevice(['enrollmentState' => 'confirmed', 'status' => 'PREPARING'], null, true));
    }

    public function testRevokedAgentHasNoTeacherActions(): void
    {
        self::assertSame([], DeviceAvailableActions::forDevice($this->device('revoked'), null, true));
        self::assertSame([], DeviceAvailableActions::forDevice($this->device('revoked'), ['status' => 'ACTIVE'], true));
    }

    public function testRepeatedEndRequestCannotQueueAnotherEndAction(): void
    {
        self::assertContains('end_session', DeviceAvailableActions::forDevice($this->device(), ['status' => 'ACTIVE'], true));
        self::assertNotContains('end_session', DeviceAvailableActions::forDevice($this->device(), ['status' => 'ACTIVE', 'desiredStatus' => 'ENDING'], true));
        self::assertNotContains('end_session', DeviceAvailableActions::forDevice($this->device(), ['status' => 'ACTIVE'], false));
    }

    public function testRecoveryCanResumeFailedSessionWithoutPermittingDirectActive(): void
    {
        self::assertTrue(SessionTransitions::allowed('FAILED', 'RECOVERY_REQUIRED'));
        self::assertFalse(SessionTransitions::allowed('FAILED', 'ACTIVE'));
        self::assertFalse(SessionTransitions::allowed('ACTIVE', 'CLEANUP'));
        self::assertFalse(SessionTransitions::allowed('SYNCING_OUT', 'CLOSED'));
        self::assertTrue(SessionTransitions::isTransitionBlocking('SYNC_PENDING'));
        self::assertTrue(SessionTransitions::isTransitionBlocking('RECOVERY_REQUIRED'));
    }

    private function device(string $enrollment = 'confirmed'): array
    {
        return ['enrollmentState' => $enrollment, 'status' => 'AVAILABLE'];
    }

    private function rejects(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected device operation rejection: ' . $reason);
        } catch (DeviceError $error) {
            self::assertSame($reason, $error->reason);
        }
    }
}

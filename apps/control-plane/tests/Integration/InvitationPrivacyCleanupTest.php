<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\CreateCommercialAccount;
use ControlPlane\Privacy\ControlPrivacyCleanup;
use ControlPlane\Privacy\ControlPrivacyRetentionPolicy;

final class InvitationPrivacyCleanupTest extends DatabaseTestCase
{
    public function testInvitationCleanupRespectsHoldForInviterRecipientAndAcceptedBy(): void
    {
        $owner = $this->user('cleanup-owner@example.test');
        $recipient = $this->user('cleanup-recipient@example.test');
        $acceptedBy = $this->user('cleanup-accepted@example.test');
        $cleanInviter = $this->user('cleanup-inviter@example.test');
        $account = (new CreateCommercialAccount($this->connection))->create($owner, 'cleanup-invitations', 'Cleanup', null, 'cleanup-key', $this->uuid());
        $cleanup = new ControlPrivacyCleanup($this->connection, new ControlPrivacyRetentionPolicy(['invitation' => 1]));

        // Invitations for the recipient/accepted_by cases use an unheld inviter,
        // otherwise the inviter hold would mask a regression in those branches.
        $inviterHeld = $this->invitation($account, $owner, 'inviter-held@example.test');
        $recipientHeld = $this->invitation($account, $cleanInviter, 'cleanup-recipient@example.test');
        $acceptedHeld = $this->invitation($account, $cleanInviter, 'accepted-held@example.test', $acceptedBy);
        $unheld = $this->invitation($account, $cleanInviter, 'unheld@example.test');

        $this->hold($owner);
        $this->hold($recipient);
        $this->hold($acceptedBy);

        self::assertSame(0, $cleanup->run('invitation', dryRun: false, recordId: $inviterHeld)['deleted']);
        self::assertSame(0, $cleanup->run('invitation', dryRun: false, recordId: $recipientHeld)['deleted']);
        self::assertSame(0, $cleanup->run('invitation', dryRun: false, recordId: $acceptedHeld)['deleted']);

        $this->connection->executeStatement(
            'UPDATE commercial.privacy_holds SET released_at = now() WHERE control_user_id = ? AND released_at IS NULL',
            [$owner],
        );
        self::assertSame(1, $cleanup->run('invitation', dryRun: false, recordId: $inviterHeld)['deleted']);
        self::assertSame(0, $cleanup->run('invitation', dryRun: false, recordId: $recipientHeld)['deleted']);
        self::assertSame(0, $cleanup->run('invitation', dryRun: false, recordId: $acceptedHeld)['deleted']);
        self::assertSame(1, $cleanup->run('invitation', dryRun: false, recordId: $unheld)['deleted']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.commercial_account_invitations WHERE id IN (?, ?)', [$inviterHeld, $unheld]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.commercial_account_invitations WHERE id = ?', [$recipientHeld]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.commercial_account_invitations WHERE id = ?', [$acceptedHeld]));
    }

    private function invitation(string $accountId, string $inviterId, string $email, ?string $acceptedBy = null): string
    {
        $id = $this->uuid();
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO commercial.commercial_account_invitations
 (id, commercial_account_id, email, email_normalized, role, invited_by, token_hash, status,
  created_at, updated_at, expires_at, accepted_by, accepted_at)
VALUES (?, ?, ?, lower(?), 'technical', ?, decode(?, 'hex'), ?, now() - interval '5 days', now() - interval '5 days',
        now() - interval '4 days', ?, CASE WHEN ?::uuid IS NULL THEN NULL ELSE now() - interval '4 days' END)
SQL, [$id, $accountId, $email, $email, $inviterId, bin2hex(random_bytes(32)), $acceptedBy === null ? 'pending' : 'accepted', $acceptedBy, $acceptedBy]);

        return $id;
    }

    private function hold(string $userId): string
    {
        $id = (string) $this->connection->fetchOne(
            "INSERT INTO commercial.privacy_holds (control_user_id, reason_code) VALUES (?, 'legal_hold') RETURNING id",
            [$userId],
        );

        return $id;
    }
}

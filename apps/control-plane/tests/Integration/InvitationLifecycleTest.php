<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\CreateCommercialAccount;
use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Account\Invitation\Application\InvitationService;
use ControlPlane\Identity\Application\AuthFailure;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class InvitationLifecycleTest extends DatabaseTestCase
{
    public function testCreateListAndPreviewNeverExposeTokens(): void
    {
        $owner = $this->user('inv-owner@example.test');
        $account = $this->accountFor($owner, 'inv-account');
        $service = $this->service();

        $created = $service->create($owner, $account, 'invitee@example.test', 'technical', 'create-key-1', $this->uuid());
        self::assertSame('pending', $created['status']);
        self::assertSame('invitee@example.test', $created['email']);
        self::assertArrayNotHasKey('token', $created);
        self::assertArrayNotHasKey('token_hash', $created);
        self::assertSame('object', $this->connection->fetchOne(
            "SELECT jsonb_typeof(metadata) FROM commercial.audit_events WHERE action = 'commercial_invitation.created' AND resource_id = ?",
            [$created['id']],
        ));

        $payload = (string) $this->connection->fetchOne(
            "SELECT payload::text FROM commercial.outbox_events WHERE event_type = 'commercial_account.invitation_requested' AND aggregate_id = ?",
            [$created['id']],
        );
        self::assertStringNotContainsString('invitee@example.test', $payload);
        self::assertStringNotContainsString('i1.', $payload);

        $list = $service->list($owner, $account, 50, 0);
        self::assertSame(1, $list['total']);
        self::assertArrayNotHasKey('token_hash', $list['items'][0]);
        self::assertSame('invitee@example.test', $list['items'][0]['email']);

        $token = $this->tokens()->invitationToken((string) $created['id'], 1);
        $preview = $service->preview($token);
        self::assertSame('pending', $preview['status']);
        self::assertSame('Invitation Org', $preview['organization_name']);
        self::assertSame('technical', $preview['role']);
        self::assertStringContainsString('***@example.test', $preview['email']);
        self::assertArrayNotHasKey('account_id', $preview);
    }

    public function testIdempotentCreateAndDuplicatePolicy(): void
    {
        $owner = $this->user('inv-dup-owner@example.test');
        $account = $this->accountFor($owner, 'inv-dup-account');
        $service = $this->service();

        $first = $service->create($owner, $account, 'dup@example.test', 'admin', 'dup-key', $this->uuid());
        $second = $service->create($owner, $account, 'dup@example.test', 'admin', 'dup-key', $this->uuid());
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.commercial_account_invitations WHERE commercial_account_id = ?', [$account]));

        try {
            $service->create($owner, $account, 'dup@example.test', 'admin', 'other-key', $this->uuid());
            self::fail('Duplicate active invitation was created.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        try {
            $service->create($owner, $account, 'dup@example.test', 'billing', 'dup-key', $this->uuid());
            self::fail('Idempotency key was reused with a different role.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }

    public function testResendRotatesTheTokenAndRevokeBlocksAcceptance(): void
    {
        $owner = $this->user('inv-resend-owner@example.test');
        $invitee = $this->user('inv-resend-invitee@example.test');
        $account = $this->accountFor($owner, 'inv-resend-account');
        $service = $this->service();

        $created = $service->create($owner, $account, 'inv-resend-invitee@example.test', 'billing', 'resend-key', $this->uuid());
        $oldToken = $this->tokens()->invitationToken((string) $created['id'], 1);
        $service->resend($owner, $account, (string) $created['id'], $this->uuid());
        $newToken = $this->tokens()->invitationToken((string) $created['id'], 2);

        try {
            $service->accept($invitee, $oldToken, $this->uuid());
            self::fail('The old token was accepted after resend.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $accepted = $service->accept($invitee, $newToken, $this->uuid());
        self::assertSame('accepted', $accepted['status']);
        self::assertSame($account, $accepted['account_id']);
        self::assertSame('billing', $accepted['role']);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM commercial.commercial_account_memberships WHERE control_user_id = ? AND commercial_account_id = ? AND revoked_at IS NULL',
            [$invitee, $account],
        ));

        try {
            $service->accept($invitee, $newToken, $this->uuid());
            self::fail('The invitation was accepted twice.');
        } catch (AuthFailure $failure) {
            self::assertSame('invitation_not_available', $failure->errorCode);
        }
        try {
            $service->revoke($owner, $account, (string) $created['id'], $this->uuid());
            self::fail('An accepted invitation was revoked.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }

    public function testDeclineCreatesNoMembership(): void
    {
        $owner = $this->user('inv-decline-owner@example.test');
        $invitee = $this->user('inv-decline-invitee@example.test');
        $account = $this->accountFor($owner, 'inv-decline-account');
        $service = $this->service();

        $created = $service->create($owner, $account, 'inv-decline-invitee@example.test', 'technical', 'decline-key', $this->uuid());
        $token = $this->tokens()->invitationToken((string) $created['id'], 1);
        $result = $service->decline($invitee, $token, $this->uuid());
        self::assertSame('declined', $result['status']);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM commercial.commercial_account_memberships WHERE control_user_id = ? AND commercial_account_id = ?',
            [$invitee, $account],
        ));
        try {
            $service->accept($invitee, $token, $this->uuid());
            self::fail('A declined invitation was accepted.');
        } catch (AuthFailure $failure) {
            self::assertSame('invitation_not_available', $failure->errorCode);
        }
    }

    public function testEmailMismatchAndUnverifiedRecipientAreRejected(): void
    {
        $owner = $this->user('inv-email-owner@example.test');
        $unverified = $this->uuid();
        $this->connection->executeStatement(
            "INSERT INTO commercial.control_users (id, email, email_normalized, password_hash, status, password_changed_at) VALUES (?, 'inv-unverified@example.test', 'inv-unverified@example.test', '!test-hash!', 'pending_verification', now())",
            [$unverified],
        );
        $account = $this->accountFor($owner, 'inv-email-account');
        $service = $this->service();
        $created = $service->create($owner, $account, 'inv-email-stranger@example.test', 'admin', 'email-key', $this->uuid());
        $token = $this->tokens()->invitationToken((string) $created['id'], 1);

        try {
            $service->accept($this->user('inv-other@example.test'), $token, $this->uuid());
            self::fail('A different email accepted the invitation.');
        } catch (AuthFailure $failure) {
            self::assertSame('invitation_email_mismatch', $failure->errorCode);
        }
        try {
            $service->accept($unverified, $token, $this->uuid());
            self::fail('An unverified user accepted the invitation.');
        } catch (AuthFailure $failure) {
            self::assertSame('email_verification_required', $failure->errorCode);
        }
    }

    public function testExpiredInvitationIsRejectedAndCanBeReplaced(): void
    {
        $owner = $this->user('inv-expire-owner@example.test');
        $invitee = $this->user('inv-expire-invitee@example.test');
        $account = $this->accountFor($owner, 'inv-expire-account');
        $service = $this->service();
        $created = $service->create($owner, $account, 'inv-expire-invitee@example.test', 'technical', 'expire-key', $this->uuid());
        $token = $this->tokens()->invitationToken((string) $created['id'], 1);
        $this->connection->executeStatement("UPDATE commercial.commercial_account_invitations SET expires_at = now() - interval '1 hour' WHERE id = ?", [$created['id']]);

        try {
            $service->accept($invitee, $token, $this->uuid());
            self::fail('An expired invitation was accepted.');
        } catch (AuthFailure $failure) {
            self::assertSame('invitation_expired', $failure->errorCode);
        }
        $preview = $service->preview($token);
        self::assertSame('expired', $preview['status']);

        $replacement = $service->create($owner, $account, 'inv-expire-invitee@example.test', 'technical', 'expire-key-2', $this->uuid());
        self::assertSame('pending', $replacement['status']);
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM commercial.commercial_account_invitations WHERE id = ?', [$created['id']]));
    }

    public function testIdempotentReplayRequiresCurrentPermission(): void
    {
        $owner = $this->user('inv-replay-owner@example.test');
        $admin = $this->user('inv-replay-admin@example.test');
        $account = $this->accountFor($owner, 'inv-replay-account');
        $memberships = new \ControlPlane\Account\Application\MembershipService($this->connection, new AccountAuthorization($this->connection));
        $adminMembership = $memberships->add($owner, $account, $admin, 'admin', $this->uuid());
        $service = $this->service();

        $created = $service->create($admin, $account, 'replay@example.test', 'technical', 'replay-key', $this->uuid());
        $memberships->remove($owner, $account, $adminMembership, $this->uuid());

        try {
            $service->create($admin, $account, 'replay@example.test', 'technical', 'replay-key', $this->uuid());
            self::fail('A revoked administrator replayed the invitation result.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        try {
            $service->create($this->user('inv-replay-stranger@example.test'), $account, 'replay@example.test', 'technical', 'replay-key', $this->uuid());
            self::fail('An unrelated user replayed another account invitation.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $retried = $service->create($owner, $account, 'replay@example.test', 'technical', 'replay-key', $this->uuid());
        self::assertSame($created['id'], $retried['id']);
    }

    public function testRevokePendingInvitationStoresObjectMetadata(): void
    {
        $owner = $this->user('inv-revoke-owner@example.test');
        $account = $this->accountFor($owner, 'inv-revoke-account');
        $service = $this->service();
        $created = $service->create($owner, $account, 'revoke@example.test', 'admin', 'revoke-key', $this->uuid());

        $service->revoke($owner, $account, (string) $created['id'], $this->uuid());

        self::assertSame('revoked', $this->connection->fetchOne('SELECT status FROM commercial.commercial_account_invitations WHERE id = ?', [$created['id']]));
        self::assertSame('object', $this->connection->fetchOne(
            "SELECT jsonb_typeof(metadata) FROM commercial.audit_events WHERE action = 'commercial_invitation.revoked' AND resource_id = ?",
            [$created['id']],
        ));
    }

    public function testRolePolicyForInvitations(): void
    {
        $owner = $this->user('inv-role-owner@example.test');
        $admin = $this->user('inv-role-admin@example.test');
        $billing = $this->user('inv-role-billing@example.test');
        $account = $this->accountFor($owner, 'inv-role-account');
        $memberships = new \ControlPlane\Account\Application\MembershipService($this->connection, new AccountAuthorization($this->connection));
        $memberships->add($owner, $account, $admin, 'admin', $this->uuid());
        $memberships->add($owner, $account, $billing, 'billing', $this->uuid());
        $service = $this->service();

        $created = $service->create($admin, $account, 'invited-by-admin@example.test', 'technical', 'admin-invite-key', $this->uuid());
        self::assertSame('pending', $created['status']);

        try {
            $service->create($billing, $account, 'forbidden@example.test', 'technical', 'billing-invite-key', $this->uuid());
            self::fail('Billing created an invitation.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $stranger = $this->user('inv-role-stranger@example.test');
        $foreign = $this->accountFor($stranger, 'inv-role-foreign');
        try {
            $service->create($owner, $foreign, 'cross@example.test', 'technical', 'cross-invite-key', $this->uuid());
            self::fail('Cross-account invitation was created.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
    }

    public function testAcceptByExistingMemberDoesNotEscalateRole(): void
    {
        $owner = $this->user('inv-existing-owner@example.test');
        $member = $this->user('inv-existing-member@example.test');
        $account = $this->accountFor($owner, 'inv-existing-account');
        (new \ControlPlane\Account\Application\MembershipService($this->connection, new AccountAuthorization($this->connection)))
            ->add($owner, $account, $member, 'billing', $this->uuid());
        $service = $this->service();
        $created = $service->create($owner, $account, 'inv-existing-member@example.test', 'technical', 'existing-key', $this->uuid());
        $token = $this->tokens()->invitationToken((string) $created['id'], 1);

        $accepted = $service->accept($member, $token, $this->uuid());

        self::assertSame('billing', $accepted['role']);
        self::assertSame('billing', $this->connection->fetchOne(
            'SELECT role FROM commercial.commercial_account_memberships WHERE control_user_id = ? AND commercial_account_id = ?',
            [$member, $account],
        ));
    }

    private function service(): InvitationService
    {
        $tokens = $this->tokens();

        return new InvitationService(
            $this->connection,
            new AccountAuthorization($this->connection),
            $tokens,
            new DatabaseRateLimiter($this->connection, $tokens),
            604800,
        );
    }

    private function tokens(): TokenCodec
    {
        return new TokenCodec('test-only-control-token-secret-at-least-32-chars');
    }

    private function accountFor(string $owner, string $code): string
    {
        return (new CreateCommercialAccount($this->connection))->create($owner, $code, 'Invitation Org', null, $code . '-key', $this->uuid());
    }
}

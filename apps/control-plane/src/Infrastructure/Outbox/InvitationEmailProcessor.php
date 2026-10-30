<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Outbox;

use ControlPlane\Identity\Security\TokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class InvitationEmailProcessor implements EmailEventProcessor
{
    private const EVENT = 'commercial_account.invitation_requested';
    private const MAX_ATTEMPTS = 5;
    private const LEASE_SECONDS = 60;

    public function __construct(
        private Connection $connection,
        private TokenCodec $tokens,
        private EmailTransport $transport,
        private string $invitationUrl,
        private string $messageIdDomain,
    ) {
        if (!str_starts_with($invitationUrl, 'https://') && !str_starts_with($invitationUrl, 'http://localhost:')) {
            throw new \InvalidArgumentException('Invitation URL must use HTTPS outside localhost.');
        }
        if (preg_match('/^[a-z0-9.-]+$/i', $messageIdDomain) !== 1) {
            throw new \InvalidArgumentException('Invalid Message-ID domain.');
        }
    }

    public function processOne(): bool
    {
        $claim = $this->claim();
        if ($claim === null) {
            return false;
        }
        try {
            $message = $this->message($claim);
            $this->transport->send(
                $message['recipient'],
                $message['subject'],
                $message['text'],
                $message['html'],
                $message['message_id'],
                'control_account_invitation',
                ['contact_email', 'invitation_link', 'organization_name'],
            );
            $this->connection->executeStatement(<<<'SQL'
UPDATE commercial.outbox_events
SET status = 'published', published_at = now(), locked_at = NULL, lock_token = NULL,
    last_error = NULL, payload = '{}'::jsonb
WHERE id = ? AND status = 'processing' AND lock_token = ?
SQL, [$claim['id'], $claim['lock_token']]);
        } catch (InvalidEmailEvent) {
            $this->fail($claim, true, 'invalid_event');
        } catch (\Throwable) {
            // Never persist/log a transport exception: it can contain credentials,
            // recipient data or message content.
            $this->fail($claim, false, 'transport_failure');
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function claim(): ?array
    {
        return $this->connection->transactional(function (Connection $connection): ?array {
            $connection->executeStatement(<<<'SQL'
UPDATE commercial.outbox_events
SET status = 'dead', locked_at = NULL, lock_token = NULL, last_error = 'attempts_exhausted'
WHERE event_type = ? AND status IN ('failed','processing') AND attempts >= ?
  AND (status = 'failed' OR locked_at < now() - (? * interval '1 second'))
SQL, [self::EVENT, self::MAX_ATTEMPTS, self::LEASE_SECONDS]);
            $row = $connection->fetchAssociative(<<<'SQL'
SELECT id, event_id, schema_version, payload, attempts
FROM commercial.outbox_events
WHERE event_type = ? AND attempts < ?
  AND ((status IN ('pending','failed') AND available_at <= now())
       OR (status = 'processing' AND locked_at < now() - (? * interval '1 second')))
ORDER BY available_at, id
FOR UPDATE SKIP LOCKED
LIMIT 1
SQL, [self::EVENT, self::MAX_ATTEMPTS, self::LEASE_SECONDS]);
            if ($row === false) {
                return null;
            }
            $lockToken = Uuid::v7()->toRfc4122();
            $connection->executeStatement(<<<'SQL'
UPDATE commercial.outbox_events
SET status = 'processing', attempts = attempts + 1,
    locked_at = now(), lock_token = ?, last_error = NULL
WHERE id = ?
SQL, [$lockToken, $row['id']]);
            $row['lock_token'] = $lockToken;
            $row['attempts'] = (int) $row['attempts'] + 1;

            return $row;
        });
    }

    /** @param array<string, mixed> $claim
     *  @return array{recipient: string, subject: string, text: string, html: string, message_id: string}
     */
    private function message(array $claim): array
    {
        if ((int) $claim['schema_version'] !== 1 || !is_string($claim['payload'])) {
            throw new InvalidEmailEvent();
        }
        $payload = json_decode($claim['payload'], true);
        if (!is_array($payload)) {
            throw new InvalidEmailEvent();
        }
        $invitationId = $payload['invitation_id'] ?? null;
        $accountId = $payload['account_id'] ?? null;
        if (!is_string($invitationId) || !Uuid::isValid($invitationId)
            || !is_string($accountId) || !Uuid::isValid($accountId)
        ) {
            throw new InvalidEmailEvent();
        }
        $invitation = $this->connection->fetchAssociative(<<<'SQL'
SELECT i.id, i.email, i.role, i.token_version, i.expires_at, a.display_name
FROM commercial.commercial_account_invitations i
JOIN commercial.commercial_accounts a ON a.id = i.commercial_account_id
WHERE i.id = ? AND i.commercial_account_id = ? AND i.status = 'pending' AND i.expires_at > now()
SQL, [$invitationId, $accountId]);
        if ($invitation === false || filter_var($invitation['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidEmailEvent();
        }
        $token = $this->tokens->invitationToken($invitationId, (int) $invitation['token_version']);
        $url = rtrim($this->invitationUrl, '#') . '#token=' . rawurlencode($token);
        $organization = (string) $invitation['display_name'];
        $role = match ((string) $invitation['role']) {
            'admin' => 'Administrator',
            'billing' => 'Billing',
            'technical' => 'Technical',
            default => 'Member',
        };
        $expires = (string) $invitation['expires_at'];
        $text = "You have been invited to join $organization on Platform as $role.\n\nAccept the invitation: $url\n\nThis invitation expires on $expires. If you were not expecting it, you can ignore this email.";
        $html = '<p>You have been invited to join <strong>' . htmlspecialchars($organization, ENT_QUOTES, 'UTF-8')
            . '</strong> on Platform as <strong>' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Accept invitation</a></p>'
            . '<p>This invitation expires on ' . htmlspecialchars($expires, ENT_QUOTES, 'UTF-8')
            . '. If you were not expecting it, you can ignore this email.</p>';

        return [
            'recipient' => $invitation['email'],
            'subject' => 'Invitation to join ' . $organization . ' on Platform',
            'text' => $text,
            'html' => $html,
            'message_id' => (string) $claim['event_id'] . '@' . $this->messageIdDomain,
        ];
    }

    /** @param array<string, mixed> $claim */
    private function fail(array $claim, bool $permanent, string $code): void
    {
        $dead = $permanent || (int) $claim['attempts'] >= self::MAX_ATTEMPTS;
        $delay = min(3600, 30 * (2 ** max(0, (int) $claim['attempts'] - 1)));
        $this->connection->executeStatement(<<<'SQL'
UPDATE commercial.outbox_events
SET status = ?, available_at = now() + (? * interval '1 second'),
    locked_at = NULL, lock_token = NULL, last_error = ?
WHERE id = ? AND status = 'processing' AND lock_token = ?
SQL, [$dead ? 'dead' : 'failed', $dead ? 0 : $delay, $code, $claim['id'], $claim['lock_token']]);
    }
}

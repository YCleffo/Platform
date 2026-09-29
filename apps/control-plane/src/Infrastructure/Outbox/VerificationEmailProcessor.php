<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Outbox;

use ControlPlane\Identity\Security\TokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class VerificationEmailProcessor
{
    private const EVENT = 'control_user.email_verification_requested';
    private const MAX_ATTEMPTS = 5;
    private const LEASE_SECONDS = 60;

    public function __construct(
        private Connection $connection,
        private TokenCodec $tokens,
        private EmailTransport $transport,
        private string $verificationUrl,
        private string $messageIdDomain,
    ) {
        if (!str_starts_with($verificationUrl, 'https://') && !str_starts_with($verificationUrl, 'http://localhost:')) {
            throw new \InvalidArgumentException('Verification URL must use HTTPS outside localhost.');
        }
        if (preg_match('/^[a-z0-9.-]+$/i', $messageIdDomain) !== 1) {
            throw new \InvalidArgumentException('Invalid Message-ID domain.');
        }
    }

    /** Process at most one event; false means there was no eligible work. */
    public function processOne(): bool
    {
        $claim = $this->claim();
        if ($claim === null) {
            return false;
        }
        try {
            $message = $this->message($claim);
            $this->transport->send($message['recipient'], $message['subject'], $message['text'], $message['html'], $message['message_id']);
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
        $verificationId = $payload['verification_id'] ?? null;
        $userId = $payload['user_id'] ?? null;
        if (!is_string($verificationId) || !Uuid::isValid($verificationId)
            || !is_string($userId) || !Uuid::isValid($userId)
        ) {
            throw new InvalidEmailEvent();
        }
        $recipient = $this->connection->fetchOne(<<<'SQL'
SELECT u.email FROM commercial.control_user_email_verifications v
 JOIN commercial.control_users u ON u.id = v.control_user_id
 WHERE v.id = ? AND v.control_user_id = ?
   AND u.status = 'pending_verification'
   AND v.consumed_at IS NULL AND v.invalidated_at IS NULL AND v.expires_at > now()
SQL, [$verificationId, $userId]);
        if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidEmailEvent();
        }
        $url = $this->verificationUrl . (str_contains($this->verificationUrl, '?') ? '&' : '?')
            . 'token=' . rawurlencode($this->tokens->verificationToken($verificationId));
        $text = "Verify your Platform email address: $url";

        return [
            'recipient' => $recipient,
            'subject' => 'Verify your Platform email address',
            'text' => $text,
            'html' => '<p>Verify your Platform email address:</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Verify email</a></p>',
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

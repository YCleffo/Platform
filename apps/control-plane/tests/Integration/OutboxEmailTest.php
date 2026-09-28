<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Infrastructure\Outbox\EmailTransport;
use ControlPlane\Infrastructure\Outbox\VerificationEmailProcessor;

final class OutboxEmailTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Other integration tests may commit registration fixtures for race
        // checks. Defer their mail inside this test transaction only.
        $this->connection->executeStatement("UPDATE commercial.outbox_events SET available_at = now() + interval '1 day' WHERE event_type = 'control_user.email_verification_requested' AND status IN ('pending','failed')");
        $this->connection->executeStatement("UPDATE commercial.outbox_events SET locked_at = now() WHERE event_type = 'control_user.email_verification_requested' AND status = 'processing'");
    }

    public function testVerificationEmailPublishesWithStableMessageId(): void
    {
        $registered = $this->authService()->register('mail@example.test', 'correct horse battery staple', '192.0.2.1', $this->uuid());
        $transport = new RecordingEmailTransport();
        $processor = $this->processor($transport);

        self::assertTrue($processor->processOne());
        self::assertFalse($processor->processOne());
        self::assertCount(1, $transport->messages);
        $row = $this->connection->fetchAssociative("SELECT event_id, status, attempts, published_at, lock_token FROM commercial.outbox_events WHERE aggregate_id = ?", [$registered['user_id']]);
        self::assertIsArray($row);
        self::assertSame('published', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['published_at']);
        self::assertNull($row['lock_token']);
        self::assertSame($row['event_id'] . '@platform.test', $transport->messages[0]['message_id']);
        self::assertStringContainsString('v1.', $transport->messages[0]['text']);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.outbox_events SET status = 'failed' WHERE aggregate_id = ?", [$registered['user_id']]));
    }

    public function testTransportFailureRetriesThenDeadAndCanBeRequeued(): void
    {
        $registered = $this->authService()->register('retry@example.test', 'correct horse battery staple', '192.0.2.1', $this->uuid());
        $transport = new RecordingEmailTransport();
        $transport->fail = true;
        $processor = $this->processor($transport);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertTrue($processor->processOne());
            $row = $this->connection->fetchAssociative('SELECT id, status, attempts, last_error FROM commercial.outbox_events WHERE aggregate_id = ?', [$registered['user_id']]);
            self::assertIsArray($row);
            self::assertSame($attempt === 5 ? 'dead' : 'failed', $row['status']);
            self::assertSame($attempt, (int) $row['attempts']);
            self::assertSame('transport_failure', $row['last_error']);
            if ($attempt < 5) {
                $this->connection->executeStatement("UPDATE commercial.outbox_events SET available_at = now() - interval '1 second' WHERE id = ?", [$row['id']]);
            }
        }
        self::assertFalse($processor->processOne());
        $this->connection->executeStatement("UPDATE commercial.outbox_events SET status = 'pending', attempts = 0, available_at = now() WHERE id = ? AND status = 'dead'", [$row['id']]);
        $transport->fail = false;
        self::assertTrue($processor->processOne());
        self::assertSame('published', $this->connection->fetchOne('SELECT status FROM commercial.outbox_events WHERE id = ?', [$row['id']]));
    }

    public function testInvalidOrExpiredVerificationIsDeadLettered(): void
    {
        $registered = $this->authService()->register('invalid@example.test', 'correct horse battery staple', '192.0.2.1', $this->uuid());
        $this->connection->executeStatement("UPDATE commercial.control_user_email_verifications SET created_at = now() - interval '2 hours', expires_at = now() - interval '1 hour' WHERE control_user_id = ?", [$registered['user_id']]);
        $transport = new RecordingEmailTransport();
        self::assertTrue($this->processor($transport)->processOne());
        self::assertSame([], $transport->messages);
        self::assertSame('dead', $this->connection->fetchOne('SELECT status FROM commercial.outbox_events WHERE aggregate_id = ?', [$registered['user_id']]));
    }

    public function testExpiredLeaseIsReclaimed(): void
    {
        $registered = $this->authService()->register('lease@example.test', 'correct horse battery staple', '192.0.2.1', $this->uuid());
        $this->connection->executeStatement("UPDATE commercial.outbox_events SET status = 'processing', attempts = 1, locked_at = now() - interval '2 minutes', lock_token = ? WHERE aggregate_id = ?", [$this->uuid(), $registered['user_id']]);
        $transport = new RecordingEmailTransport();
        self::assertTrue($this->processor($transport)->processOne());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT attempts FROM commercial.outbox_events WHERE aggregate_id = ?', [$registered['user_id']]));
    }

    private function processor(EmailTransport $transport): VerificationEmailProcessor
    {
        return new VerificationEmailProcessor($this->connection, new TokenCodec('test-only-control-token-secret-at-least-32-chars'), $transport, 'https://platform.test/verify', 'platform.test');
    }
}

final class RecordingEmailTransport implements EmailTransport
{
    public bool $fail = false;

    /** @var list<array{recipient: string, subject: string, text: string, html: string, message_id: string}> */
    public array $messages = [];

    public function send(string $recipient, string $subject, string $text, string $html, string $messageId): void
    {
        if ($this->fail) {
            throw new \RuntimeException('secret transport error must not be logged');
        }
        $this->messages[] = compact('recipient', 'subject', 'text', 'html') + ['message_id' => $messageId];
    }
}

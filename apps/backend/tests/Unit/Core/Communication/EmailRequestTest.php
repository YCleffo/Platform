<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Communication;

use App\Core\Communication\Application\EmailEventPayloadException;
use App\Core\Communication\Application\EmailEventType;
use App\Core\Communication\Application\EmailRequest;
use App\Core\Internationalization\Application\LocaleNormalizer;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Messaging\Application\OutboxEvent;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

final class EmailRequestTest extends Unit
{
    public function testMapsNamedAuthEventToTemplate(): void
    {
        $request = EmailRequest::fromEvent($this->event([
            'schemaVersion' => 1,
            'recipient' => ['address' => 'user@example.test', 'name' => 'Yuran'],
            'locale' => 'en',
            'variables' => [
                'displayName' => 'Yuran',
                'actionUrl' => 'https://example.test/verify',
            ],
        ]), $this->locales());

        assertSame('auth.email.verification', $request->templateCode);
        assertSame('user@example.test', $request->recipientAddress);
        assertSame('en-US', $request->locale);
    }

    public function testRejectsInvalidAddress(): void
    {
        $this->expectException(EmailEventPayloadException::class);

        EmailRequest::fromEvent($this->event([
            'schemaVersion' => 1,
            'recipient' => ['address' => 'not-an-email'],
            'locale' => 'ru',
            'variables' => [],
        ]), $this->locales());
    }

    /** @param array<array-key, mixed> $payload */
    private function event(array $payload): OutboxEvent
    {
        return new OutboxEvent(
            id: 1,
            eventId: '018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
            producerModule: 'auth',
            eventType: EmailEventType::EMAIL_VERIFICATION_REQUESTED,
            aggregateType: null,
            aggregateId: null,
            payload: $payload,
            headers: [],
            correlationId: null,
            causationId: null,
            status: 'processing',
            attempts: 1,
            occurredAt: '2026-09-20T00:00:00+00:00',
            availableAt: '2026-09-20T00:00:00+00:00',
            publishedAt: null,
            lockedBy: 'test',
            lockedUntil: '2026-09-20T00:01:00+00:00',
            lastError: null,
        );
    }

    private function locales(): SupportedLocales
    {
        return new SupportedLocales(new LocaleNormalizer(), ['ru-RU', 'en-US'], 'ru-RU');
    }
}

<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Infrastructure\Outbox\EmailTransport;
use ControlPlane\Infrastructure\Outbox\SecurityEmailProcessor;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class SecurityEmailContextTest extends TestCase
{
    public function testResetAndChangedMessagesIdentifyTheCustomerAccount(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn('owner@example.test');
        $processor = new SecurityEmailProcessor($db, new TokenCodec(str_repeat('a', 64)), $this->createMock(EmailTransport::class), 'https://platform.test/reset-password', 'platform.test');
        $method = new \ReflectionMethod($processor, 'message');
        foreach (['control_user.password_reset_requested', 'control_user.password_changed'] as $type) {
            $message = $method->invoke($processor, [
                'payload' => json_encode(['user_id' => '01900000-0000-7000-8000-000000000001', 'reset_id' => '01900000-0000-7000-8000-000000000002'], JSON_THROW_ON_ERROR),
                'event_type' => $type, 'event_id' => '01900000-0000-7000-8000-000000000003',
            ]);
            self::assertIsArray($message);
            self::assertStringStartsWith('[Platform Cabinet]', $message[1]);
            self::assertStringContainsString('Platform customer account', $message[2] . $message[3]);
            self::assertSame('01900000-0000-7000-8000-000000000003@platform.test', $message[4]);
        }
    }
}

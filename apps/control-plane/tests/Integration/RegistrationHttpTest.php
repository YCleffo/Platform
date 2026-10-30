<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

final class RegistrationHttpTest extends DatabaseTestCase
{
    public function testHttpRegistrationQueuesVerificationAndMatchesOpenApi(): void
    {
        $kernel = new Kernel('test', false);
        $email = 'registration-' . bin2hex(random_bytes(8)) . '@example.test';
        $request = Request::create('/api/control/auth/register', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '192.0.2.' . random_int(1, 254),
        ], content: json_encode(['email' => $email, 'password' => 'Registration-http-fixture-9!'], JSON_THROW_ON_ERROR));
        try {
            $response = $kernel->handle($request);
            self::assertSame(202, $response->getStatusCode());
            $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('verification_required', $body['status']);
            self::assertArrayNotHasKey('verification_token', $body);
            $userId = $body['user_id'];
            self::assertIsString($userId);
            self::assertSame('pending_verification', $this->connection->fetchOne(
                'SELECT status FROM commercial.control_users WHERE id = ?', [$userId],
            ));
            self::assertSame(1, (int) $this->connection->fetchOne(
                "SELECT count(*) FROM commercial.outbox_events WHERE aggregate_id = ? AND event_type = 'control_user.email_verification_requested' AND status = 'pending'",
                [$userId],
            ));
            $path = getenv('CONTROL_OPENAPI_FILE') ?: '/contracts/control-plane.yaml';
            if (!is_file($path)) {
                $path = dirname(__DIR__, 4) . '/contracts/openapi/control-plane.yaml';
            }
            $contract = Yaml::parseFile($path);
            self::assertIsArray($contract);
            self::assertArrayHasKey($response->getStatusCode(), $contract['paths']['/api/control/auth/register']['post']['responses']);
        } finally {
            $kernel->shutdown();
        }
    }
}

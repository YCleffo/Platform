<?php

declare(strict_types=1);

namespace App\DeviceManagement\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationStore;
use DateTimeImmutable;
use DateTimeZone;

final readonly class DeviceService
{
    public function __construct(
        private DeviceStore $store,
        private Mutation $mutations,
        private EducationStore $educationStore,
        private EducationAccess $educationAccess,
    ) {}

    /** @return array<string,mixed> */
    public function read(string $operation, Scope $scope, array $query, ?string $id = null): array
    {
        $offset = max(0, (int) ($query['offset'] ?? 0));
        return match ($operation) {
            'classrooms' => ['items' => $this->store->classrooms($scope)],
            'devices' => $this->pageOf(fn(): array => $this->store->devices($scope, $offset), $offset),
            'device' => $this->deviceDetail($scope, self::uuid($id)),
            'device_events' => $this->pageOf(fn(): array => $this->store->deviceEvents($scope, self::uuid($id), $offset), $offset),
            default => throw new DeviceError(404, 'not_found'),
        };
    }

    /**
     * @param callable():list<array<string,mixed>> $fetch
     * @return array<string,mixed>
     */
    private function pageOf(callable $fetch, int $offset): array
    {
        $items = $fetch();
        return ['items' => array_slice($items, 0, 50), 'limit' => 50, 'offset' => $offset,
            'nextOffset' => count($items) > 50 ? $offset + 50 : null];
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function write(string $operation, Scope $scope, array $body, ?string $id = null): array
    {
        return match ($operation) {
            'create_classroom' => $this->mutations->run($scope, 'create_classroom',
                fn(): array => $this->store->createClassroom($scope, self::text($body['name'] ?? null, 160))),
            'create_device' => $this->mutations->run($scope, 'create_device',
                fn(): array => $this->store->createDevice($scope,
                    empty($body['classroomId']) ? null : self::uuid($body['classroomId']),
                    self::text($body['name'] ?? '', 160, false), self::text($body['number'] ?? '', 40, false))),
            'device_enroll' => $this->enroll($scope, self::uuid($id)),
            'device_session' => $this->startSession($scope, self::uuid($id), $body),
            'device_session_end' => $this->endSession($scope, self::uuid($id), $body),
            'revoke_device' => $this->mutations->run($scope, 'revoke_device',
                function () use ($scope, $id): array {
                    $this->store->revokeDevice($scope, self::uuid($id));
                    return ['id' => $id, 'revoked' => true];
                }),
            default => throw new DeviceError(404, 'not_found'),
        };
    }

    /** @return array<string,mixed> */
    private function deviceDetail(Scope $scope, string $id): array
    {
        $device = $this->store->device($scope, $id) ?? throw new DeviceError(404, 'not_found');
        return [...$device, 'sessions' => $this->store->deviceSessions($scope, $id)];
    }

    /** @return array<string,mixed> */
    private function enroll(Scope $scope, string $deviceId): array
    {
        $device = $this->store->device($scope, $deviceId) ?? throw new DeviceError(404, 'not_found');
        if ($device['enrollmentState'] === 'revoked') {
            throw new DeviceError(409, 'device_revoked');
        }
        $code = bin2hex(random_bytes(12));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+15 minutes')->format(DateTimeImmutable::ATOM);
        $enrollment = $this->store->createEnrollment($scope, $deviceId, hash('sha256', $code), $expiresAt);
        return [...$enrollment, 'code' => $code];
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    private function startSession(Scope $scope, string $deviceId, array $body): array
    {
        $studentId = self::uuid($body['studentId'] ?? null);
        $educationScope = new \App\Education\Application\Scope($scope->organizationId, $scope->accountId,
            $scope->personId, $scope->locale, $scope->timezone);
        $student = $this->educationStore->student($educationScope, $studentId) ?? throw new DeviceError(404, 'not_found');
        $personId = (string) $student['personId'];
        if (!$this->educationAccess->member($personId, $scope->organizationId)
            || !$this->educationAccess->resourceAllowed($personId, 'student', $studentId)) {
            throw new DeviceError(403, 'access_denied');
        }
        $name = $this->educationAccess->personName($personId) ?? '';
        return $this->mutations->run($scope, 'start_session',
            fn(): array => $this->store->startSession($scope, $deviceId, $studentId, ['personId' => $personId, 'name' => $name]));
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    private function endSession(Scope $scope, string $deviceId, array $body): array
    {
        $sessionId = self::uuid($body['sessionId'] ?? null);
        return $this->mutations->run($scope, 'end_session', function () use ($scope, $deviceId, $sessionId): array {
            $this->store->requestSessionEnd($scope, $deviceId, $sessionId);
            return ['sessionId' => $sessionId, 'ending' => true];
        });
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function agentEnroll(array $body): array
    {
        $code = self::text($body['code'] ?? null, 64);
        $enrollment = $this->store->findEnrollment(hash('sha256', $code));
        if ($enrollment === null) {
            throw new DeviceError(404, 'not_found');
        }
        $credential = bin2hex(random_bytes(32));
        $result = $this->store->confirmEnrollment((string) $enrollment['id'], (string) $enrollment['deviceId'], hash('sha256', $credential));
        return [...$result, 'credential' => $credential];
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function agentHeartbeat(string $deviceId, array $body): array
    {
        $state = is_array($body['state'] ?? null) ? $body['state'] : [];
        $this->store->heartbeat($deviceId, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $session = $this->store->activeSession($deviceId);
        return ['status' => 'ok', 'session' => $session];
    }

    /** @return array<string,mixed> */
    public function agentCommands(string $deviceId): array
    {
        return ['items' => array_map(self::decodeCommand(...), $this->store->pendingCommands($deviceId))];
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function agentAcknowledge(string $deviceId, string $commandId, array $body): array
    {
        self::only($body, []);
        return $this->store->acknowledgeCommand($deviceId, self::uuid($commandId));
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function agentSessionState(string $deviceId, string $sessionId, array $body): array
    {
        self::only($body, ['status']);
        $status = self::text($body['status'] ?? null, 24);
        SessionTransitions::validate($status);
        return $this->store->transitionSession($deviceId, $sessionId, $status);
    }

    /** @return array<string,mixed> */
    public function agentSession(string $deviceId): array
    {
        $session = $this->store->activeSession($deviceId);
        if ($session === null) {
            return ['session' => null];
        }
        return ['session' => $session];
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function agentEvent(string $deviceId, array $body): array
    {
        self::only($body, ['kind', 'payload']);
        $kind = self::text($body['kind'] ?? null, 60);
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $this->store->recordEvent($deviceId, $kind, $payload);
        return ['recorded' => true];
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    private static function decodeCommand(array $command): array
    {
        $payload = is_string($command['payload'] ?? null) ? json_decode((string) $command['payload'], true) : null;
        return [...$command, 'payload' => is_array($payload) ? $payload : []];
    }

    private static function text(mixed $value, int $maximum, bool $required = true): string
    {
        if (!is_string($value) || trim($value) === '' && $required
            || strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new DeviceError(422, 'invalid_input');
        }
        return trim($value);
    }

    private static function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new DeviceError(422, 'invalid_input');
        }
        return $value;
    }

    /**
     * @param array<array-key,mixed> $body
     * @param list<string> $keys
     */
    private static function only(array $body, array $keys): void
    {
        if (array_diff(array_keys($body), $keys) !== []) {
            throw new DeviceError(422, 'invalid_input');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\DeviceManagement\Application;

interface DeviceStore
{
    /** @return array{id:string,name:string,studentId:string,organizationId:string,status:string}|null */
    public function classroom(Scope $scope, string $id): ?array;
    /** @return list<array<string,mixed>> */
    public function classrooms(Scope $scope): array;
    /**
     * @return array<string,mixed>
     */
    public function createClassroom(Scope $scope, string $name): array;
    /** @return list<array<string,mixed>> */
    public function devices(Scope $scope, int $offset): array;
    /** @return array<string,mixed>|null */
    public function device(Scope $scope, string $id): ?array;
    /** @return list<array<string,mixed>> */
    public function deviceSessions(Scope $scope, string $deviceId): array;
    /**
     * @return array<string,mixed>
     */
    public function createDevice(Scope $scope, ?string $classroomId, string $name, string $number): array;
    /**
     * @return array<string,mixed>
     */
    public function createEnrollment(Scope $scope, string $deviceId, string $codeHash, string $expiresAt): array;
    /** @return array<string,mixed>|null */
    public function findEnrollment(string $codeHash): ?array;
    /**
     * @return array<string,mixed>
     */
    public function confirmEnrollment(string $enrollmentId, string $deviceId, string $credentialHash): array;
    /** @return array<string,mixed>|null */
    public function credentialDevice(string $credentialHash): ?array;
    public function revokeDevice(Scope $scope, string $deviceId): void;
    public function heartbeat(string $deviceId, string $state): void;
    /** @return array<string,mixed>|null */
    public function activeSession(string $deviceId): ?array;
    /**
     * @return array<string,mixed>
     */
    public function startSession(Scope $scope, string $deviceId, string $studentId, array $student): array;
    /**
     * @return array<string,mixed>|null
     */
    public function session(Scope $scope, string $deviceId, string $sessionId): ?array;
    public function requestSessionEnd(Scope $scope, string $deviceId, string $sessionId): void;
    /**
     * @return array<string,mixed>
     */
    public function transitionSession(string $deviceId, string $sessionId, string $status): array;
    /**
     * @return array<string,mixed>
     */
    public function queueCommand(string $deviceId, string $kind, array $payload, string $expiresAt): array;
    /** @return list<array<string,mixed>> */
    public function pendingCommands(string $deviceId): array;
    /** @return array<string,mixed>|null */
    public function command(string $deviceId, string $commandId): ?array;
    /**
     * @return array<string,mixed>
     */
    public function acknowledgeCommand(string $deviceId, string $commandId): array;
    public function recordEvent(string $deviceId, string $kind, array $payload): void;
    /** @return list<array<string,mixed>> */
    public function deviceEvents(Scope $scope, string $deviceId, int $offset): array;
    public function markSeen(string $deviceId): void;
}

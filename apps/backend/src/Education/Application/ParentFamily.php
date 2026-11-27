<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Account\Application\CreateAccount;
use App\Core\Account\Application\CreateAccountCommand;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\AuthIdentifierNormalizer;
use App\Core\Auth\Application\CompleteAccountActivation;
use App\Core\Auth\Application\CreateAuthChallengeCommand;
use App\Core\Auth\Application\CredentialLifecycleRepository;
use App\Core\Auth\Application\OpaqueTokenService;
use App\Core\Client\Domain\ClientContext;
use App\Core\Module\Application\ModuleRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Rbac\Application\RoleRepository;
use App\Core\Time\Application\ClockInterface;
use App\Education\Infrastructure\EducationChatBridge;
use App\Education\Infrastructure\ParentNotifications;
use App\Realtime\Application\RealtimeService;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Family evidence is separate from Core account ownership and login activation. */
final readonly class ParentFamily
{
    public function __construct(
        private ConnectionInterface $db,
        private EducationAccess $access,
        private EducationStore $education,
        private PermissionService $permissions,
        private Mutation $mutations,
        private ClockInterface $clock,
        private RoleRepository $roles,
        private CreateAccount $accounts,
        private CredentialLifecycleRepository $credentials,
        private AuthIdentifierNormalizer $identifiers,
        private OpaqueTokenService $tokens,
        private AuthChallengeRepository $challenges,
        private CompleteAccountActivation $activation,
        private ModuleRepository $modules,
        private \App\Core\Security\Application\RateLimiterInterface $rateLimiter,
        private \App\Core\Security\Application\IdentifierDigestService $digests,
        private ?RealtimeService $realtime = null,
    ) {}

    public function list(Scope $scope, string $studentId): array
    {
        $this->student($scope, $studentId);
        $rows = $this->db->createCommand('SELECT sg.guardian_person_id::text AS "personId",sg.relationship,sg.status,
            sg.confirmed_at::text AS "confirmedAt",sg.revoked_at::text AS "revokedAt",
            pa.account_id::text AS "accountId",pa.activated_at::text AS "activatedAt",
            i.id::text AS "inviteId",i.expires_at::text AS "expiresAt",i.used_at::text AS "usedAt",i.revoked_at::text AS "inviteRevokedAt"
            FROM education.student_guardians sg
            LEFT JOIN education.parent_accounts pa ON pa.guardian_person_id=sg.guardian_person_id
                AND pa.organization_id=sg.organization_id AND pa.revoked_at IS NULL
            LEFT JOIN LATERAL (SELECT * FROM education.parent_invites pi WHERE pi.student_id=sg.student_id
                AND pi.guardian_person_id=sg.guardian_person_id ORDER BY pi.created_at DESC,pi.id DESC LIMIT 1) i ON true
            WHERE sg.student_id=CAST(:student AS uuid) AND sg.organization_id=CAST(:org AS uuid)
            ORDER BY sg.created_at,sg.guardian_person_id', [':student' => $studentId,':org' => $scope->organizationId])->queryAll();
        $items = [];
        foreach ($rows as $row) {
            if (!$this->access->member($row['personId'], $scope->organizationId)) {
                continue;
            }
            $name = $this->access->personName($row['personId']);
            if ($name === null) {
                continue;
            }
            $items[] = ['personId' => $row['personId'],'name' => $name,'relationship' => $row['relationship'],'status' => $row['status'],
                'confirmedAt' => self::instant($row['confirmedAt']),'revokedAt' => self::instant($row['revokedAt']),
                'account' => $row['accountId'] === null ? null : ['accountId' => $row['accountId'],'activatedAt' => self::instant($row['activatedAt'])],
                'invitation' => $row['inviteId'] === null ? null : ['id' => $row['inviteId'],'expiresAt' => self::instant($row['expiresAt']),
                    'usedAt' => self::instant($row['usedAt']),'revokedAt' => self::instant($row['inviteRevokedAt'])]];
        }
        return ['items' => $items,'canManage' => $this->permissions->hasPermission($scope->actor(), 'education.family.manage'),
            'canConfirm' => $this->permissions->hasPermission($scope->actor(), 'education.family.confirm')];
    }

    public function history(Scope $scope, string $studentId, array $query = []): array
    {
        $this->student($scope, $studentId);
        $offset = Input::page($query['offset'] ?? null);
        $rows = $this->db->createCommand(
            'SELECT id::text,guardian_person_id::text AS "personId",actor_account_id::text AS "actorAccountId",
            action,relationship,status,created_at::text AS "createdAt" FROM education.family_history
            WHERE student_id=CAST(:student AS uuid) AND organization_id=CAST(:org AS uuid)
            ORDER BY created_at DESC,id DESC LIMIT 51 OFFSET :offset',
            [':student' => $studentId,':org' => $scope->organizationId,':offset' => $offset],
        )->queryAll();
        foreach ($rows as &$row) {
            $row['createdAt'] = self::instant($row['createdAt']);
        }unset($row);
        return self::page($rows, $offset);
    }

    public function candidates(Scope $scope, array $query = []): array
    {
        if (!$this->permissions->hasPermission($scope->actor(), 'education.family.manage')) {
            throw new EducationError(403, 'access_denied');
        }
        $q = Input::text($query['q'] ?? '', 120, false);
        $items = [];
        foreach ($this->access->candidates($scope) as $item) {
            if ($q === '' || mb_stripos($item['name'], $q) !== false) {
                $items[] = $item;
            }
            if (count($items) === 50) {
                break;
            }
        }
        return ['items' => $items];
    }

    public function write(string $operation, Scope $scope, string $studentId, ?string $personId, array $input = []): array
    {
        $this->student($scope, $studentId);
        $permission = $operation === 'confirm' ? 'education.family.confirm' : 'education.family.manage';
        if (!$this->permissions->hasPermission($scope->actor(), $permission)) {
            throw new EducationError(403, 'access_denied');
        }
        self::only($input, match ($operation) {
            'add' => ['personId','relationship'],'update' => ['relationship'],default => [],
        });
        $personId = Input::uuid($operation === 'add' ? ($input['personId'] ?? null) : $personId);
        if (!$this->access->member($personId, $scope->organizationId) || $this->access->personName($personId) === null
            || !$this->access->resourceAllowed($personId, 'family', $studentId)) {
            throw new EducationError(404, 'not_found');
        }
        if ($this->education->student($scope, $studentId)['personId'] === $personId) {
            throw new EducationError(422, 'invalid_guardian');
        }
        $relationship = in_array($operation, ['add','update'], true) ? self::relationship($input['relationship'] ?? null) : null;
        /** @var callable():array<string, mixed> $mutation */
        $mutation = function () use ($operation, $scope, $studentId, $personId, $relationship): array {
            $params = [':org' => $scope->organizationId,':student' => $studentId,':person' => $personId];
            // Serialize duplicate creation and every state transition for this student.
            $this->db->createCommand('SELECT id FROM education.students WHERE id=CAST(:student AS uuid)
                AND organization_id=CAST(:org AS uuid) FOR UPDATE', [':student' => $studentId,':org' => $scope->organizationId])->queryOne();
            $row = $this->db->createCommand('SELECT * FROM education.student_guardians WHERE student_id=CAST(:student AS uuid)
                AND organization_id=CAST(:org AS uuid) AND guardian_person_id=CAST(:person AS uuid) FOR UPDATE', $params)->queryOne();
            $now = $this->clock->now()->format(DATE_ATOM);
            if ($operation === 'add') {
                if (is_array($row)) {
                    throw new EducationError(409, 'family_exists');
                }
                $this->db->createCommand('INSERT INTO education.student_guardians(organization_id,student_id,guardian_person_id,relationship)
                    VALUES(CAST(:org AS uuid),CAST(:student AS uuid),CAST(:person AS uuid),:relationship)', [...$params,':relationship' => $relationship])->execute();
            } else {
                if (!is_array($row)) {
                    throw new EducationError(404, 'not_found');
                }
                if ($operation === 'update') {
                    $this->db->createCommand(
                        'UPDATE education.student_guardians SET relationship=:relationship,updated_at=CAST(:now AS timestamptz)
                        WHERE student_id=CAST(:student AS uuid) AND guardian_person_id=CAST(:person AS uuid) AND organization_id=CAST(:org AS uuid)',
                        [...$params,':relationship' => $relationship,':now' => $now],
                    )->execute();
                } elseif ($operation === 'confirm') {
                    if ($row['status'] === 'confirmed') {
                        throw new EducationError(409, 'already_confirmed');
                    }
                    $this->db->createCommand(
                        "UPDATE education.student_guardians SET status='confirmed',confirmed_at=CAST(:now AS timestamptz),
                        confirmed_by_account_id=CAST(:actor AS uuid),revoked_at=NULL,revoked_by_account_id=NULL,updated_at=CAST(:now AS timestamptz)
                        WHERE student_id=CAST(:student AS uuid) AND guardian_person_id=CAST(:person AS uuid) AND organization_id=CAST(:org AS uuid)",
                        [...$params,':actor' => $scope->actor(),':now' => $now],
                    )->execute();
                } elseif ($operation === 'revoke') {
                    $this->revokeInvites($params, $now);
                    $this->db->createCommand(
                        "UPDATE education.student_guardians SET status='revoked',revoked_at=CAST(:now AS timestamptz),
                        revoked_by_account_id=CAST(:actor AS uuid),updated_at=CAST(:now AS timestamptz)
                        WHERE student_id=CAST(:student AS uuid) AND guardian_person_id=CAST(:person AS uuid) AND organization_id=CAST(:org AS uuid)",
                        [...$params,':actor' => $scope->actor(),':now' => $now],
                    )->execute();
                    $roomIds = $this->db->createCommand(
                        "SELECT r.id::text FROM chat.rooms r
                         JOIN chat.members m ON m.room_id=r.id AND m.organization_id=r.organization_id
                         WHERE r.organization_id=CAST(:org AS uuid) AND r.context_type='student' AND r.context_id=CAST(:student AS uuid)
                           AND m.person_id=CAST(:person AS uuid) AND m.left_at IS NULL",
                        $params,
                    )->queryColumn();
                    $bridge = new EducationChatBridge($this->db, $this->realtime);
                    foreach ($roomIds as $rid) {
                        if (is_string($rid) && $rid !== '') {
                            $bridge->revokeMemberAccess($scope->organizationId, $rid, $personId, $scope->actor(), $scope->personId);
                        }
                    }
                } elseif ($operation === 'invite') {
                    if ($row['status'] !== 'confirmed') {
                        throw new EducationError(409, 'family_unconfirmed');
                    }
                    $this->revokeInvites($params, $now);
                    $code = bin2hex(random_bytes(32));
                    $expires = $this->clock->now()->modify('+72 hours')->format(DATE_ATOM);
                    $invite = $this->db->createCommand(
                        'INSERT INTO education.parent_invites(organization_id,student_id,guardian_person_id,
                        code_hash,expires_at,created_by_account_id,created_at) VALUES(CAST(:org AS uuid),CAST(:student AS uuid),CAST(:person AS uuid),
                        :hash,CAST(:expires AS timestamptz),CAST(:actor AS uuid),CAST(:now AS timestamptz)) RETURNING id::text',
                        [...$params,':hash' => hash('sha256', $code),':expires' => $expires,':actor' => $scope->actor(),':now' => $now],
                    )->queryOne();
                    $this->record($params, $scope->actor(), 'invited');
                    $this->notify($scope, $studentId, $personId, 'invited');
                    return ['id' => $invite['id'],'code' => $code,'expiresAt' => $expires,'studentId' => $studentId,'personId' => $personId];
                } elseif ($operation === 'revoke_invite') {
                    $this->revokeInvites($params, $now);
                } else {
                    throw new EducationError(404, 'not_found');
                }
            }
            $event = match ($operation) {
                'add' => 'added','update' => 'relationship_changed','confirm' => 'confirmed','revoke' => 'revoked','revoke_invite' => 'invitation_revoked',
            };
            $this->record($params, $scope->actor(), $event);
            $this->notify($scope, $studentId, $personId, $event);
            return $this->list($scope, $studentId);
        };
        return $this->mutations->run($scope, 'family_' . $operation, $mutation);
    }

    public function activate(AuthenticatedIdentity $identity, array $input): array
    {
        self::only($input, ['code']);
        $person = $identity->personId;
        if ($person === null) {
            throw new EducationError(403, 'access_denied');
        }
        return $this->db->transaction(function () use ($identity, $input, $person): array {
            $invite = $this->invite($input['code'] ?? null);
            if ($invite['guardian_person_id'] !== $person) {
                throw new EducationError(404, 'not_found');
            }
            $account = $this->db->createCommand(
                "SELECT id FROM core.accounts WHERE id=CAST(:id AS uuid) AND person_id=CAST(:person AS uuid)
                AND account_type='human' AND status='active' AND disabled_at IS NULL AND archived_at IS NULL",
                [':id' => $identity->accountId,':person' => $person],
            )->queryOne();
            if (!is_array($account)) {
                throw new EducationError(403, 'access_denied');
            }
            return $this->accept($invite, $identity->accountId);
        });
    }

    /** Invitation possession enrolls exactly the verified Person through existing Core credentials. */
    public function register(array $input, ClientContext $context): array
    {
        self::only($input, ['code','email','password']);
        if (!$this->rateLimiter->consume('education:parent-register:ip', $this->digests->digest($context->ipAddress ?? 'unknown'), 10, 300)) {
            throw new EducationError(429, 'rate_limited');
        }
        $email = Input::text($input['email'] ?? null, 254);
        $password = $input['password'] ?? null;
        if (!is_string($password) || mb_strlen($password) < 12 || mb_strlen($password) > 1024) {
            throw new EducationError(422, 'invalid_password');
        }
        try {
            $normalized = $this->identifiers->normalize('email', $email);
        } catch (\InvalidArgumentException) {
            throw new EducationError(422, 'invalid_email');
        }
        if (!$this->rateLimiter->consume('education:parent-register:identifier', $this->digests->digest($normalized), 5, 300)) {
            throw new EducationError(429, 'rate_limited');
        }
        return $this->db->transaction(function () use ($input, $email, $normalized, $password, $context): array {
            $invite = $this->invite($input['code'] ?? null);
            $person = $invite['guardian_person_id'];
            $this->db->createCommand('SELECT id FROM core.persons WHERE id=CAST(:person AS uuid) FOR UPDATE', [':person' => $person])->queryOne();
            // An invitation can never reset/take over an existing Core identity.
            if (is_array($this->db->createCommand('SELECT id FROM core.accounts WHERE person_id=CAST(:person AS uuid) LIMIT 1', [':person' => $person])->queryOne())) {
                throw new EducationError(409, 'core_login_required');
            }
            if (is_array($this->db->createCommand("SELECT id FROM core.auth_identifiers WHERE identifier_type='email' AND normalized_value=:email LIMIT 1", [':email' => $normalized])->queryOne())) {
                throw new EducationError(409, 'identity_conflict');
            }
            $account = $this->accounts->execute(new CreateAccountCommand('human', $person, $this->access->personName($person)))
                ?? throw new EducationError(404, 'not_found');
            if ($this->credentials->ensurePrimaryEmail($account->id, $email, $normalized) === null) {
                throw new EducationError(409, 'identity_conflict');
            }
            $token = $this->tokens->issue();
            $expiresAt = new \DateTimeImmutable((string) $invite['expires_at']);
            $now = new \DateTimeImmutable();
            if ($expiresAt <= $now) {
                $expiresAt = $now->modify('+72 hours');
            }
            $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
                $account->id,
                'account_activation',
                $token->hashHex,
                $normalized,
                ['purpose' => 'account_activation'],
                5,
                $expiresAt->format(DATE_ATOM),
            ));
            if (!$this->activation->execute($token->raw, $password, $context)) {
                throw new EducationError(409, 'core_activation_failed');
            }
            $this->accept($invite, $account->id);
            return ['activated' => true];
        });
    }

    private function invite(mixed $code): array
    {
        if ($this->modules->findByCode('education')?->enabled !== true) {
            throw new EducationError(404, 'module_unavailable');
        }
        $code = Input::text($code, 64);
        if (preg_match('/^[a-f0-9]{64}$/D', $code) !== 1) {
            throw new EducationError(404, 'invitation_invalid');
        }
        $hash = hash('sha256', $code);
        $target = $this->db->createCommand('SELECT student_id::text,organization_id::text FROM education.parent_invites
            WHERE code_hash=:hash', [':hash' => $hash])->queryOne();
        if (!is_array($target)) {
            throw new EducationError(404, 'invitation_invalid');
        }
        // Every family transition locks student -> family edge -> invitation. A reverse order
        // deadlocks with staff revocation when history insertion checks the student FK.
        $studentLock = $this->db->createCommand(
            'SELECT id FROM education.students WHERE id=CAST(:student AS uuid)
            AND organization_id=CAST(:org AS uuid) FOR UPDATE',
            [':student' => $target['student_id'],':org' => $target['organization_id']],
        )->queryOne();
        if (!is_array($studentLock)) {
            throw new EducationError(404, 'invitation_invalid');
        }
        $row = $this->db->createCommand(
            "SELECT i.* FROM education.parent_invites i
            JOIN education.student_guardians sg ON sg.student_id=i.student_id AND sg.guardian_person_id=i.guardian_person_id
                AND sg.organization_id=i.organization_id
            JOIN education.students s ON s.id=i.student_id AND s.organization_id=i.organization_id
            WHERE i.code_hash=:hash AND sg.status='confirmed' AND i.used_at IS NULL AND i.revoked_at IS NULL FOR UPDATE OF i,sg",
            [':hash' => $hash],
        )->queryOne();
        if (!is_array($row) || new DateTimeImmutable($row['expires_at']) <= $this->clock->now()) {
            throw new EducationError(404, 'invitation_invalid');
        }
        $student = $this->db->createCommand('SELECT person_id::text FROM education.students WHERE id=CAST(:id AS uuid)', [':id' => $row['student_id']])->queryScalar();
        if (!$this->access->member($row['guardian_person_id'], $row['organization_id']) || !is_string($student)
            || !$this->access->member($student, $row['organization_id'])
            || !$this->access->resourceAllowed($student, 'student', $row['student_id'])
            || !$this->access->resourceAllowed($row['guardian_person_id'], 'family', $row['student_id'])) {
            throw new EducationError(404, 'invitation_invalid');
        }
        return $row;
    }

    private function accept(array $invite, string $account): array
    {
        $scope = new Scope($invite['organization_id'], $account, $invite['guardian_person_id'], 'ru-RU', 'UTC');
        return $this->mutations->run($scope, 'parent_activate', function () use ($invite, $account): array {
            $now = $this->clock->now()->format(DATE_ATOM);
            $this->db->createCommand(
                'UPDATE education.parent_invites SET used_at=CAST(:now AS timestamptz),used_account_id=CAST(:account AS uuid)
                WHERE id=CAST(:id AS uuid) AND used_at IS NULL AND revoked_at IS NULL',
                [':id' => $invite['id'],':now' => $now,':account' => $account],
            )->execute();
            $this->db->createCommand(
                'INSERT INTO education.parent_accounts(organization_id,guardian_person_id,account_id,activated_at)
                VALUES(CAST(:org AS uuid),CAST(:person AS uuid),CAST(:account AS uuid),CAST(:now AS timestamptz))
                ON CONFLICT(organization_id,guardian_person_id) DO UPDATE SET account_id=EXCLUDED.account_id,activated_at=EXCLUDED.activated_at,revoked_at=NULL',
                [':org' => $invite['organization_id'],':person' => $invite['guardian_person_id'],':account' => $account,':now' => $now],
            )->execute();
            $role = $this->db->createCommand("SELECT id::text FROM core.roles WHERE code='education.parent'")->queryScalar();
            if (!is_string($role)) {
                throw new EducationError(409, 'parent_role_missing');
            }
            $this->roles->assign($account, $role, $invite['created_by_account_id']);
            $this->record([':org' => $invite['organization_id'],':student' => $invite['student_id'],':person' => $invite['guardian_person_id']], $account, 'activated');
            return ['activated' => true,'studentId' => $invite['student_id'],'organizationId' => $invite['organization_id']];
        });
    }

    private function student(Scope $scope, string $student): void
    {
        Input::uuid($student);
        $this->access->scope(
            new AuthenticatedIdentity($scope->actor(), $scope->personId, '', [], [], $scope->locale, $scope->timezone),
            $scope->organizationId,
            'education.people.manage',
        );
        $active = $this->db->createCommand("SELECT id FROM core.accounts WHERE id=CAST(:actor AS uuid) AND person_id=CAST(:person AS uuid)
            AND status='active' AND disabled_at IS NULL AND archived_at IS NULL", [':actor' => $scope->actor(),':person' => $scope->personId])->queryOne();
        if (!is_array($active)) {
            throw new EducationError(403, 'access_denied');
        }
        if (!$this->permissions->hasPermission($scope->actor(), 'education.people.manage')) {
            throw new EducationError(403, 'access_denied');
        }
        $row = $this->education->student($scope, $student);
        if ($row === null || !$this->access->member($row['personId'], $scope->organizationId)
            || !$this->access->resourceAllowed($row['personId'], 'student', $student)) {
            throw new EducationError(404, 'not_found');
        }
        $this->access->assertResource($scope, 'student', $student);
    }

    private function record(array $params, string $actor, string $event): void
    {
        $this->db->createCommand(
            'INSERT INTO education.family_history(organization_id,student_id,guardian_person_id,actor_account_id,
            action,relationship,status,created_at) SELECT organization_id,student_id,guardian_person_id,CAST(:actor AS uuid),:event,relationship,status,
            CAST(:now AS timestamptz) FROM education.student_guardians WHERE organization_id=CAST(:org AS uuid)
            AND student_id=CAST(:student AS uuid) AND guardian_person_id=CAST(:person AS uuid)',
            [...$params,':actor' => $actor,':event' => $event,':now' => $this->clock->now()->format(DATE_ATOM)],
        )->execute();
    }
    private function revokeInvites(array $params, string $now): void
    {
        $this->db->createCommand('UPDATE education.parent_invites SET revoked_at=CAST(:now AS timestamptz)
            WHERE organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid) AND guardian_person_id=CAST(:person AS uuid)
            AND used_at IS NULL AND revoked_at IS NULL', [...$params,':now' => $now])->execute();
    }
    private function notify(Scope $scope, string $student, string $person, string $event): void
    {
        (new ParentNotifications($this->db))->family($scope, $student, $person, $event);
    }
    private static function relationship(mixed $value): string
    {
        if (!in_array($value, ['mother','father','guardian'], true)) {
            throw new EducationError(422, 'invalid_relationship');
        }return $value;
    }
    public static function instant(?string $value): ?string
    {
        return $value === null ? null : (new DateTimeImmutable($value))->format(DATE_ATOM);
    }
    private static function only(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new EducationError(422, 'invalid_input');
        }
    }
    private static function page(array $items, int $offset): array
    {
        return ['items' => array_slice($items, 0, 50),'limit' => 50,'offset' => $offset,'nextOffset' => count($items) > 50 ? $offset + 50 : null];
    }
}

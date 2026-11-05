<?php

declare(strict_types=1);

use App\Core\Audit\Application\AuditService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\DestructionEvidencePolicy;
use App\Core\Privacy\Application\ErasureCaseStateMachine;
use App\Core\Privacy\Application\ErasureExecutor;
use App\Core\Privacy\Application\ErasureRequestCompletionService;
use App\Core\Privacy\Application\LawfulProcessingBasisContributorRegistry;
use App\Core\Privacy\Application\LawfulProcessingBasisResolver;
use App\Core\Privacy\Application\LegalHoldService;
use App\Core\Privacy\Application\PersonalDataErasureHandlerRegistry;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionCandidateContributor;
use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\RetentionDecisionService;
use App\Core\Privacy\Application\RetentionDependencyContributorRegistry;
use App\Core\Privacy\Application\RetentionEvaluator;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Privacy\Infrastructure\CorePersonContactErasureHandler;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

require dirname(__DIR__) . '/bootstrap.php';

[, $account, $subject, $contact, $caseId] = $argv;
$container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
$db = $container->get(ConnectionInterface::class);
$clock = $container->get(ClockInterface::class);
$audit = $container->get(AuditService::class);
$policies = new RetentionPolicyRegistry([
    new RetentionPolicyDefinition('b4.race', 'purpose_completed', null, 'delete', durationUnit: 'event_only'),
]);
$purposes = new ProcessingPurposeRegistry([
    new ProcessingPurposeDefinition(
        'b4.race',
        'consent',
        true,
        false,
        ['identity'],
        ['person'],
        'b4.race',
        'operator',
    ),
], $container->get(DataCategoryRegistry::class), $policies);
$candidates = new RetentionCandidateContributorRegistry([
    new class ($subject, $contact) implements RetentionCandidateContributor {
        public function __construct(private string $subject, private string $contact) {}
        public function code(): string
        {
            return 'b4.race';
        }
        public function supportsPurpose(string $purposeCode): bool
        {
            return $purposeCode === 'b4.race';
        }
        public function candidates(int $limit, ?string $subjectPersonId = null): iterable
        {
            if ($subjectPersonId === $this->subject) {
                yield new RetentionCandidate(
                    $this->subject,
                    'core.person_contact',
                    $this->contact,
                    'b4.race',
                    'b4.race',
                    'purpose_completed',
                    new DateTimeImmutable('2026-01-01T00:00:00Z'),
                    'b4.race',
                );
            }
        }
    },
]);
$basis = new LawfulProcessingBasisResolver($purposes, new LawfulProcessingBasisContributorRegistry(), $db);
$evaluator = new RetentionEvaluator(
    $purposes,
    $policies,
    $container->get(PrivacyDeadlinePolicy::class),
    $container->get(LegalHoldService::class),
    $basis,
    new RetentionDependencyContributorRegistry(),
    $clock,
);
$decisions = new RetentionDecisionService($db, $evaluator, $clock, $audit);
$handlers = new PersonalDataErasureHandlerRegistry([new CorePersonContactErasureHandler($db)]);
$executor = new ErasureExecutor(
    $db,
    $clock,
    $candidates,
    $purposes,
    $decisions,
    $handlers,
    $container->get(DestructionEvidencePolicy::class),
    new ErasureCaseStateMachine(),
    $audit,
    $container->get(ErasureRequestCompletionService::class),
);
$actor = new AuthenticatedIdentity(
    $account,
    $subject,
    '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
    ['core.superadmin'],
    [],
    null,
    null,
);
try {
    echo json_encode($executor->executeBatch($actor, $caseId), JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    echo json_encode(['error' => 'execution_failed'], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}

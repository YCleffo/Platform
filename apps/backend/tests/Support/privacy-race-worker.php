<?php

declare(strict_types=1);

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\ProcessingBlockService;
use App\Core\Privacy\Application\LegalHoldService;
use App\Core\Privacy\Application\RetentionDecisionService;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Privacy\Application\RetentionCandidateContributor;
use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributor;
use App\Core\Privacy\Application\ProcessingRestrictionContributorRegistry;
use App\Core\Privacy\Application\ProcessingTerminationService;
use App\Core\Privacy\Application\LawfulProcessingBasisResolver;
use App\Core\Privacy\Application\DataSubjectRequestStateMachine;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Audit\Application\AuditService;
use App\Core\Time\Application\ClockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use App\Environment;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

require dirname(__DIR__) . '/bootstrap.php';

[$script, $operation, $accountId, $personId, $targetId] = $argv;
$container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
$actor = new AuthenticatedIdentity(
    $accountId,
    $personId,
    '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
    ['core.superadmin'],
    [],
    null,
    null,
);
try {
    $result = match ($operation) {
        'transition' => $container->get(DataSubjectRequestService::class)->transition($actor, $targetId, 'in_review', null),
        'extend' => $container->get(DataSubjectRequestService::class)->extendDeadline($actor, $targetId, 'access_response', 'complex_review')->format(DATE_ATOM),
        'create' => $container->get(ProcessingBlockService::class)->createBlock(
            $actor,
            $personId,
            'subject',
            null,
            null,
            null,
            'accuracy_review',
            $targetId,
        ),
        'release' => $container->get(ProcessingBlockService::class)->releaseBlock($actor, $targetId, 'review_complete'),
        'hold_create' => $container->get(LegalHoldService::class)->create(
            $actor,
            $personId,
            'subject',
            null,
            null,
            null,
            'legal_review',
        ),
        'hold_release' => $container->get(LegalHoldService::class)->release($actor, $targetId, 'review_complete'),
        'retention_evaluate' => $container->get(RetentionDecisionService::class)->evaluateResource([
            new RetentionCandidate(
                $personId,
                'core.person',
                $personId,
                'test.processing',
                'test.consent',
                'withdrawal',
                null,
                'b3.race',
            )], $actor)['aggregate'],
        'terminate' => (new ProcessingTerminationService(
            $container->get(ConnectionInterface::class),
            $container->get(ClockInterface::class),
            new RetentionCandidateContributorRegistry([
                new class ($personId) implements RetentionCandidateContributor {
                    public function __construct(private string $personId) {}
                    public function code(): string
                    {
                        return 'b3.race';
                    }
                    public function supportsPurpose(string $purposeCode): bool
                    {
                        return in_array($purposeCode, ['test.processing', 'test.required'], true);
                    }
                    public function candidates(int $limit, ?string $subjectPersonId = null): iterable
                    {
                        if ($subjectPersonId === $this->personId) {
                            yield new RetentionCandidate(
                                $this->personId,
                                'core.person',
                                $this->personId,
                                'test.processing',
                                'test.consent',
                                'withdrawal',
                                null,
                                'b3.race',
                            );
                        }
                    }
                },
            ]),
            $container->get(RetentionDecisionService::class),
            $container->get(LawfulProcessingBasisResolver::class),
            $container->get(ProcessingBlockService::class),
            new ProcessingRestrictionContributorRegistry([
                new class implements ProcessingRestrictionContributor {
                    public function code(): string
                    {
                        return 'b3.race';
                    }
                    public function supports(string $scopeType, ?string $purposeCode, ?string $resourceType): bool
                    {
                        return $scopeType === 'purpose' && $purposeCode === 'test.processing';
                    }
                    public function applyRestriction(string $blockId, string $subjectPersonId): void {}
                    public function releaseRestriction(string $blockId, string $subjectPersonId): void {}
                },
            ]),
            $container->get(DataSubjectRequestStateMachine::class),
            $container->get(AuditService::class),
            $container->get(ProcessingPurposeRegistry::class),
        ))->execute($actor, $targetId),
        default => throw new DomainException('Unknown operation.'),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR), "\n";
} catch (DomainException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), "\n";
}

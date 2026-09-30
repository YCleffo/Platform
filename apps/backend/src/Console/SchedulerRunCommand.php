<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Auth\Application\AuthCleanupPolicy;
use App\Core\Auth\Application\AuthCleanupRepository;
use App\Core\Privacy\Application\PendingErasureProcessor;
use App\Core\Privacy\Application\DestructionEvidencePolicy;
use App\Core\Privacy\Application\PrivacyCleanupPolicy;
use App\Core\Privacy\Application\PrivacyCleanupService;
use App\Core\Privacy\Application\RetentionBatchEvaluator;
use App\PlatformRuntime\RuntimeEntitlementSynchronizer;
use App\PlatformRuntime\Scheduler\JobSkipped;
use App\PlatformRuntime\Scheduler\ScheduledJob;
use App\PlatformRuntime\Scheduler\SchedulerConfig as Config;
use App\PlatformRuntime\Scheduler\SchedulerLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

#[AsCommand(name: 'scheduler:run', description: 'Run bounded Core background jobs continuously')]
final class SchedulerRunCommand extends Command
{
    public function __construct(private readonly ConnectionInterface $db,
        private readonly AuthCleanupRepository $auth, private readonly AuthCleanupPolicy $authPolicy,
        private readonly RetentionBatchEvaluator $retention, private readonly PendingErasureProcessor $erasure,
        private readonly PrivacyCleanupService $cleanup, private readonly PrivacyCleanupPolicy $cleanupPolicy,
        private readonly DestructionEvidencePolicy $evidencePolicy,
        private readonly RuntimeEntitlementSynchronizer $entitlements) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $enabled = Config::enabled('CORE_SCHEDULER_ENABLED', true);
            $authLimit = Config::integer('CORE_AUTH_CLEANUP_BATCH_LIMIT', 500, 1, 1000);
            $retentionLimit = Config::integer('CORE_PRIVACY_RETENTION_BATCH_LIMIT', 100);
            $erasureCases = Config::integer('CORE_PRIVACY_ERASURE_BATCH_LIMIT', 10, 1, 100);
            $erasureItems = Config::integer('CORE_PRIVACY_ERASURE_ITEM_LIMIT', 25, 1, 100);
            $cleanupLimit = Config::integer('CORE_PRIVACY_CLEANUP_BATCH_LIMIT', 100);
            $erasureEnabled = Config::enabled('CORE_PRIVACY_ERASURE_ENABLED', false);
            $cleanupEnabled = Config::enabled('CORE_PRIVACY_CLEANUP_ENABLED', false);
            $actorId = getenv('PRIVACY_AUTOMATION_ACCOUNT_ID') ?: '';
            if ($enabled && $erasureEnabled && $actorId !== '') { $this->erasure->assertActor($actorId); }
            $categories = PrivacyCleanupService::categories();
            $categoryIndex = 0;
            $jobs = [
                new ScheduledJob('auth_cleanup', Config::integer('CORE_AUTH_CLEANUP_INTERVAL_SECONDS', 3600, 1, 86400),
                    $enabled && Config::enabled('CORE_AUTH_CLEANUP_ENABLED', true), $authLimit,
                    function (int $limit): int {
                        $policy = new AuthCleanupPolicy($this->authPolicy->challengeRetentionDays,
                            $this->authPolicy->recoveryCodeRetentionDays, min($limit, $this->authPolicy->batchSize),
                            $this->authPolicy->serviceCredentialRetentionDays);
                        return $this->auth->cleanup($policy)->total();
                    }),
                new ScheduledJob('privacy_retention_evaluate', Config::integer('CORE_PRIVACY_RETENTION_INTERVAL_SECONDS', 3600, 1, 86400),
                    $enabled && Config::enabled('CORE_PRIVACY_RETENTION_ENABLED', true), $retentionLimit,
                    fn(int $limit): int => $this->retention->evaluate($limit)['candidates']),
                new ScheduledJob('privacy_erasure_execute', Config::integer('CORE_PRIVACY_ERASURE_INTERVAL_SECONDS', 300, 1, 86400),
                    $enabled && $erasureEnabled, $erasureCases,
                    function (int $limit) use ($actorId, $erasureItems): int {
                        if ($actorId === '') { throw new JobSkipped('automation_actor_missing'); }
                        if (!$this->erasure->policyReady()) { throw new JobSkipped('privacy_policy_missing'); }
                        return $this->erasure->process($actorId, $limit, $erasureItems);
                    }),
                new ScheduledJob('privacy_cleanup', Config::integer('CORE_PRIVACY_CLEANUP_INTERVAL_SECONDS', 3600, 1, 86400),
                    $enabled && $cleanupEnabled, $cleanupLimit,
                    function (int $limit) use ($categories, &$categoryIndex): int {
                        for ($i = 0; $i < count($categories); ++$i) {
                            $category = $categories[$categoryIndex++ % count($categories)];
                            if ($category === 'destruction_evidence' && !$this->evidencePolicy->available()) { continue; }
                            if ($category !== 'destruction_evidence' && $this->cleanupPolicy->days($category) === null) { continue; }
                            return $this->cleanup->execute($category, $limit, false)['deleted'];
                        }
                        throw new JobSkipped('privacy_policy_missing');
                    }),
                new ScheduledJob('runtime_entitlement_sync', Config::integer('CORE_ENTITLEMENT_SYNC_INTERVAL_SECONDS', 300, 1, 86400),
                    $enabled && Config::enabled('CORE_ENTITLEMENT_SYNC_ENABLED', true), 1,
                    function (int $limit): int { $this->entitlements->sync(); return 1; }),
            ];
            (new SchedulerLoop($this->db, $jobs, static fn(string $line) => $output->writeln($line)))->run();
        } catch (\Throwable) {
            $output->writeln('Scheduler startup failed.');
            return Command::FAILURE;
        }
    }
}

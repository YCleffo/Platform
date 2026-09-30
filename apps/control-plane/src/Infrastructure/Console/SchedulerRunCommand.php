<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Billing\Application\IssueRenewalInvoice;
use ControlPlane\Fiscalization\Application\ReconcileYooKassaReceipts;
use ControlPlane\Infrastructure\Scheduler\JobSkipped;
use ControlPlane\Infrastructure\Scheduler\ScheduledJob;
use ControlPlane\Infrastructure\Scheduler\SchedulerConfig as Config;
use ControlPlane\Infrastructure\Scheduler\SchedulerLoop;
use ControlPlane\Payment\Application\ReconcileYooKassaPayments;
use ControlPlane\Privacy\ControlPrivacyCleanup;
use ControlPlane\Privacy\ControlPrivacyRetentionPolicy;
use ControlPlane\Refund\Application\ReconcileYooKassaRefunds;
use ControlPlane\Subscription\Application\AdvanceSubscriptionPeriod;
use ControlPlane\Subscription\Application\ExpireSubscription;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:scheduler:run', description: 'Run bounded Control Plane jobs continuously')]
final class SchedulerRunCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly IssueRenewalInvoice $renewals,
        private readonly AdvanceSubscriptionPeriod $advance, private readonly ExpireSubscription $expire,
        private readonly ReconcileYooKassaPayments $payments, private readonly ReconcileYooKassaRefunds $refunds,
        private readonly ReconcileYooKassaReceipts $receipts, private readonly ControlPrivacyCleanup $cleanup,
        private readonly ControlPrivacyRetentionPolicy $cleanupPolicy, private readonly int $leadDays = 7)
    { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $enabled = Config::enabled('CONTROL_SCHEDULER_ENABLED', true);
            $subscriptionLimit = Config::integer('CONTROL_SUBSCRIPTION_BATCH_LIMIT', 100);
            $reconcileLimit = Config::integer('CONTROL_RECONCILIATION_BATCH_LIMIT', 100);
            $cleanupLimit = Config::integer('CONTROL_PRIVACY_CLEANUP_BATCH_LIMIT', 100);
            $categories = ControlPrivacyRetentionPolicy::categories();
            $categoryIndex = 0;
            $jobs = [
                new ScheduledJob('prepare_renewals', Config::integer('CONTROL_RENEWALS_INTERVAL_SECONDS', 300, 1, 86400),
                    $enabled && Config::enabled('CONTROL_RENEWALS_ENABLED', true), $subscriptionLimit,
                    fn(int $limit): int => $this->renewals->prepareDue(new \DateTimeImmutable('now'), $this->leadDays,
                        $limit, Uuid::v7()->toRfc4122())),
                new ScheduledJob('advance_subscriptions', Config::integer('CONTROL_SUBSCRIPTIONS_INTERVAL_SECONDS', 300, 1, 86400),
                    $enabled && Config::enabled('CONTROL_SUBSCRIPTIONS_ENABLED', true), $subscriptionLimit,
                    fn(int $limit): int => $this->advance->advanceDue(new \DateTimeImmutable('now'), $limit,
                        Uuid::v7()->toRfc4122())),
                new ScheduledJob('expire_subscriptions', Config::integer('CONTROL_EXPIRATION_INTERVAL_SECONDS', 300, 1, 86400),
                    $enabled && Config::enabled('CONTROL_EXPIRATION_ENABLED', true), $subscriptionLimit,
                    fn(int $limit): int => $this->expire->expireDue(new \DateTimeImmutable('now'), $limit,
                        Uuid::v7()->toRfc4122())),
                new ScheduledJob('reconcile_yookassa_payments', Config::integer('CONTROL_PAYMENT_RECONCILIATION_INTERVAL_SECONDS', 120, 1, 86400),
                    $enabled && Config::enabled('CONTROL_PAYMENT_RECONCILIATION_ENABLED', true), $reconcileLimit,
                    fn(int $limit): int => self::reconciled(
                        $this->payments->reconcile($limit, Uuid::v7()->toRfc4122()))),
                new ScheduledJob('reconcile_yookassa_refunds', Config::integer('CONTROL_REFUND_RECONCILIATION_INTERVAL_SECONDS', 120, 1, 86400),
                    $enabled && Config::enabled('CONTROL_REFUND_RECONCILIATION_ENABLED', true), $reconcileLimit,
                    fn(int $limit): int => self::reconciled(
                        $this->refunds->reconcile($limit, Uuid::v7()->toRfc4122()))),
                new ScheduledJob('reconcile_yookassa_receipts', Config::integer('CONTROL_RECEIPT_RECONCILIATION_INTERVAL_SECONDS', 120, 1, 86400),
                    $enabled && Config::enabled('CONTROL_RECEIPT_RECONCILIATION_ENABLED', true), $reconcileLimit,
                    fn(int $limit): int => self::reconciled(
                        $this->receipts->reconcile($limit, Uuid::v7()->toRfc4122()))),
                new ScheduledJob('control_privacy_cleanup', Config::integer('CONTROL_PRIVACY_CLEANUP_INTERVAL_SECONDS', 3600, 1, 86400),
                    $enabled && Config::enabled('CONTROL_PRIVACY_CLEANUP_ENABLED', false), $cleanupLimit,
                    function (int $limit) use ($categories, &$categoryIndex): int {
                        for ($i = 0; $i < count($categories); ++$i) {
                            $category = $categories[$categoryIndex++ % count($categories)];
                            try { $this->cleanupPolicy->days($category); }
                            catch (\DomainException) { continue; }
                            return $this->cleanup->run($category, $limit, false)['deleted'];
                        }
                        throw new JobSkipped('privacy_policy_missing');
                    }),
            ];
            (new SchedulerLoop($this->db, $jobs, static fn(string $line) => $output->writeln($line)))->run();
        } catch (\Throwable) {
            $output->writeln('Scheduler startup failed.');
            return Command::FAILURE;
        }
    }

    /** @param array{selected:int,failed:int} $report */
    private static function reconciled(array $report): int
    {
        if ($report['failed'] > 0) { throw new \RuntimeException('Reconciliation batch failed.'); }
        return $report['selected'];
    }
}

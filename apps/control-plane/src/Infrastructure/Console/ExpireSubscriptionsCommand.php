<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Subscription\Application\ExpireSubscription;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:subscriptions:expire', description: 'Expire active subscriptions whose current period has ended')]
final class ExpireSubscriptionsCommand extends Command
{
    public function __construct(private readonly ExpireSubscription $expiration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Maximum subscriptions locked per transaction', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawBatchSize = $input->getOption('batch-size');
        if (!is_string($rawBatchSize) || preg_match('/\A[1-9][0-9]*\z/D', $rawBatchSize) !== 1) {
            throw new \InvalidArgumentException('Batch size must be an integer between 1 and 1000.');
        }
        $batchSize = (int) $rawBatchSize;
        if ($batchSize > 1000) {
            throw new \InvalidArgumentException('Batch size must be an integer between 1 and 1000.');
        }

        $effectiveInstant = new \DateTimeImmutable('now');
        $correlationId = Uuid::v7()->toRfc4122();
        $total = 0;
        do {
            $processed = $this->expiration->expireDue($effectiveInstant, $batchSize, $correlationId);
            $total += $processed;
        } while ($processed === $batchSize);

        $output->writeln(sprintf('Expired subscriptions: %d', $total));

        return Command::SUCCESS;
    }
}

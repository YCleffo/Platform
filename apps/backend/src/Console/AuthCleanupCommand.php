<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Auth\Application\AuthCleanupPolicy;
use App\Core\Auth\Application\AuthCleanupRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'auth/cleanup',
    description: 'Deletes one bounded batch of obsolete authentication artifacts per category.',
)]
final class AuthCleanupCommand extends Command
{
    public function __construct(
        private readonly AuthCleanupRepository $repository,
        private readonly AuthCleanupPolicy $policy,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->repository->cleanup($this->policy);
        $output->writeln(sprintf('Expired challenges removed: %d', $report->expiredChallenges));
        $output->writeln(sprintf('Consumed challenges removed: %d', $report->consumedChallenges));
        $output->writeln(sprintf('Cancelled challenges removed: %d', $report->cancelledChallenges));
        $output->writeln(sprintf('Revoked/used recovery codes removed: %d', $report->recoveryCodes));
        $output->writeln(sprintf('Expired TOTP setups removed: %d', $report->pendingTotpFactors));
        $output->writeln(sprintf('Obsolete service credentials removed: %d', $report->serviceCredentials));
        $output->writeln(sprintf('Total removed: %d', $report->total()));
        return Command::SUCCESS;
    }
}

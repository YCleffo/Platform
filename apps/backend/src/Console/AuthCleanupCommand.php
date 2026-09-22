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
        $output->writeln(sprintf(
            'expired_challenges=%d consumed_challenges=%d cancelled_challenges=%d recovery_codes=%d pending_totp=%d total=%d',
            $report->expiredChallenges,
            $report->consumedChallenges,
            $report->cancelledChallenges,
            $report->recoveryCodes,
            $report->pendingTotpFactors,
            $report->total(),
        ));
        return Command::SUCCESS;
    }
}

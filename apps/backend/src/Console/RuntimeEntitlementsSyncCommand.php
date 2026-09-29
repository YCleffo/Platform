<?php

declare(strict_types=1);

namespace App\Console;

use App\PlatformRuntime\RuntimeEntitlementSynchronizer;
use DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'runtime:entitlements:sync', description: 'Pull and apply the signed deployment entitlement')]
final class RuntimeEntitlementsSyncCommand extends Command
{
    public function __construct(private readonly RuntimeEntitlementSynchronizer $synchronizer) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $sequence = $this->synchronizer->sync();
            $output->writeln(json_encode(['entitlementSequence' => $sequence], JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        } catch (DomainException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}

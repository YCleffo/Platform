<?php

declare(strict_types=1);

namespace App\Console;

use App\PlatformRuntime\RuntimeReleaseMetadataUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'runtime:release:apply', description: 'Apply release metadata from the server runtime environment')]
final class RuntimeReleaseApplyCommand extends Command
{
    public function __construct(private readonly RuntimeReleaseMetadataUpdater $updater)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->updater->apply(
                (string) getenv('PLATFORM_RELEASE_ID'),
                (string) getenv('APPLICATION_VERSION'),
                (string) getenv('PLATFORM_RELEASE_CHANNEL'),
            );
            return Command::SUCCESS;
        } catch (\Throwable) {
            return Command::FAILURE;
        }
    }
}

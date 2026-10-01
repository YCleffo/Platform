<?php

declare(strict_types=1);

namespace App\Console;

use App\PlatformRuntime\RuntimeMigrationArtifact;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'runtime:migrations:apply', description: 'Apply migrations from this immutable artifact')]
final class RuntimeMigrationsApplyCommand extends Command
{
    public function __construct(private readonly RuntimeMigrationArtifact $migrations) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try { $this->migrations->apply(); return Command::SUCCESS; }
        catch (\Throwable) { return Command::FAILURE; }
    }
}

<?php

declare(strict_types=1);

namespace App\Storage\Console;

use App\Storage\Infrastructure\BinaryCleanup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CleanupCommand extends Command
{
    public function __construct(private readonly BinaryCleanup $cleanup) { parent::__construct(); }
    protected function configure(): void { $this->setName('storage:cleanup')->setDescription('Process committed Storage erasure and orphan binary cleanup.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queued = $this->cleanup->queueOrphans();
        $deleted = $this->cleanup->drain();
        $output->writeln("Storage cleanup queued $queued orphan objects and removed $deleted objects.");
        return Command::SUCCESS;
    }
}

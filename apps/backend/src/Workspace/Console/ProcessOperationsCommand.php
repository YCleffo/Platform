<?php

declare(strict_types=1);

namespace App\Workspace\Console;

use App\Workspace\Application\WorkspaceOperationService;
use App\Workspace\Application\WorkspaceTrashService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'workspace:process-operations', description: 'Commit overdue undoable operations, run due deferred actions, and purge expired trash')]
final class ProcessOperationsCommand extends Command
{
    public function __construct(
        private readonly WorkspaceOperationService $operations,
        private readonly WorkspaceTrashService $trash,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $opsCount = $this->operations->processDue(100);
        $trashCount = $this->trash->cleanupExpired(100);
        $output->writeln(sprintf('Processed %d operations and purged %d expired trash items.', $opsCount, $trashCount));
        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:outbox:status', description: 'Show outbox status and oldest waiting event')]
final class OutboxStatusCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('requeue', null, InputOption::VALUE_REQUIRED, 'Explicitly requeue one dead event ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('requeue');
        if ($id !== null) {
            if (!is_string($id) || !ctype_digit($id)) {
                return Command::INVALID;
            }
            $changed = $this->connection->executeStatement(<<<'SQL'
UPDATE commercial.outbox_events
SET status = 'pending', attempts = 0, available_at = now(), last_error = NULL
WHERE id = ? AND status = 'dead'
SQL, [$id]);
            $output->writeln($changed === 1 ? 'Dead event requeued.' : 'No dead event with that ID.');
        }
        $rows = $this->connection->fetchAllAssociative('SELECT status, count(*) AS total FROM commercial.outbox_events GROUP BY status ORDER BY status');
        foreach ($rows as $row) {
            $output->writeln($row['status'] . ': ' . $row['total']);
        }
        $oldest = $this->connection->fetchOne("SELECT min(created_at) FROM commercial.outbox_events WHERE status IN ('pending','failed')");
        $output->writeln('Oldest waiting: ' . ($oldest ?: 'none'));

        return Command::SUCCESS;
    }
}

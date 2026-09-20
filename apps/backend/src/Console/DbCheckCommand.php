<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

#[AsCommand(
    name: 'db:check',
    description: 'Checks PostgreSQL connection and Core schema.',
)]
final class DbCheckCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $database = $this->db
            ->createCommand('SELECT current_database()')
            ->queryScalar();

        $coreTables = $this->db
            ->createCommand(
                <<<'SQL'
                SELECT count(*)
                FROM information_schema.tables
                WHERE table_schema = 'core'
                  AND table_type = 'BASE TABLE'
                SQL,
            )
            ->queryScalar();

        $output->writeln("Database: {$database}");
        $output->writeln("Core tables: {$coreTables}");

        if (!is_string($database) || $database === '') {
            $output->writeln(
                '<error>Unable to determine the current database.</error>',
            );

            return Command::FAILURE;
        }

        if ((int) $coreTables !== 23) {
            $output->writeln(
                '<error>Expected exactly 23 Core tables.</error>',
            );

            return Command::FAILURE;
        }

        $output->writeln(
            '<info>PostgreSQL connection and Core schema are OK.</info>',
        );

        return Command::SUCCESS;
    }
}

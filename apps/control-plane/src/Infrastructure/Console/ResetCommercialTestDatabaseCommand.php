<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:test:reset', description: 'Reset only the isolated Commercial test schema')]
final class ResetCommercialTestDatabaseCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = getenv('CONTROL_DATABASE_URL');
        $parts = is_string($url) ? parse_url($url) : false;
        if (getenv('APP_ENV') !== 'test'
            || getenv('CONTROL_TEST_ISOLATED') !== '1'
            || !is_array($parts)
            || ($parts['host'] ?? '') !== 'control-postgres-test'
            || ($parts['path'] ?? '') !== '/platform_commercial_test') {
            $output->writeln('<error>Refusing reset outside the isolated Commercial test database.</error>');

            return Command::FAILURE;
        }

        $this->connection->executeStatement('DROP SCHEMA IF EXISTS commercial CASCADE');
        $this->connection->executeStatement('DROP TABLE IF EXISTS public.doctrine_migration_versions');
        $output->writeln('Isolated Commercial test schema reset.');

        return Command::SUCCESS;
    }
}

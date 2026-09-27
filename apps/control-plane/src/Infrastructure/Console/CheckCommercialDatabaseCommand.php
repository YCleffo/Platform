<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:db:check', description: 'Verify that the connection targets the current Commercial database schema')]
final class CheckCommercialDatabaseCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly string $databaseUrl)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expected = parse_url($this->databaseUrl, PHP_URL_PATH);
        $expected = is_string($expected) ? ltrim($expected, '/') : '';
        $actual = (string) $this->connection->fetchOne('SELECT current_database()');
        $tableCount = (int) $this->connection->fetchOne("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'commercial' AND table_type = 'BASE TABLE'");
        $latest = (bool) $this->connection->fetchOne("SELECT EXISTS (SELECT 1 FROM doctrine_migration_versions WHERE version LIKE '%Version20260924001900' AND executed_at IS NOT NULL)");
        $objects = (bool) $this->connection->fetchOne(<<<'SQL'
SELECT to_regclass('commercial.control_users') IS NOT NULL
   AND to_regclass('commercial.subscriptions') IS NOT NULL
   AND to_regclass('commercial.invoices') IS NOT NULL
   AND to_regclass('commercial.payments') IS NOT NULL
   AND to_regclass('commercial.payment_attempts') IS NOT NULL
   AND to_regclass('commercial.payment_provider_events') IS NOT NULL
   AND to_regclass('commercial.refunds') IS NOT NULL
   AND to_regclass('commercial.refund_items') IS NOT NULL
   AND to_regclass('commercial.receipts') IS NOT NULL
   AND to_regclass('commercial.receipt_items') IS NOT NULL
SQL);
        $yii = (bool) $this->connection->fetchOne("SELECT to_regclass('core.accounts') IS NOT NULL OR to_regclass('core.users') IS NOT NULL");
        $ready = $expected !== '' && $actual === $expected && $tableCount === 39 && $latest && $objects && !$yii;
        $output->writeln($ready ? 'Commercial database: ready (migration 019, tables 39).' : 'Commercial database: not ready.');

        return $ready ? Command::SUCCESS : Command::FAILURE;
    }
}

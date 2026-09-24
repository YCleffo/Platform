<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:security:cleanup', description: 'Delete expired Control Plane verification, session, rate-limit and idempotency records')]
final class CleanupControlSecurityCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $counts = $this->connection->transactional(function (Connection $connection): array {
            $verification = $connection->executeStatement("DELETE FROM commercial.control_user_email_verifications WHERE expires_at < now() - interval '7 days'");
            $sessions = $connection->executeStatement("DELETE FROM commercial.control_sessions WHERE expires_at < now() - interval '7 days' OR revoked_at < now() - interval '30 days'");
            $rates = $connection->executeStatement('DELETE FROM commercial.rate_limit_counters WHERE expires_at < now()');
            $idempotency = $connection->executeStatement("DELETE FROM commercial.idempotency_keys WHERE expires_at IS NOT NULL AND expires_at < now() AND status <> 'pending'");

            return compact('verification', 'sessions', 'rates', 'idempotency');
        });
        $output->writeln(json_encode($counts, JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Restricted to operators with Control Plane console access. Never changes financial truth. */
#[AsCommand(name: 'control:plan-changes:recovery-cases', description: 'List paid plan changes requiring compensation recovery')]
final class PlanChangeRecoveryCasesCommand extends Command
{
    public function __construct(private readonly Connection $connection) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum cases to list', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('limit');
        if (!is_string($raw) || preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1 || (int) $raw > 1000) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000.');
        }
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT c.id AS change_id, c.commercial_account_id, c.compensation_status, c.compensation_reason,
 c.invoice_id, p.id AS payment_id, r.id AS refund_id, r.status AS refund_status, c.updated_at
FROM commercial.subscription_plan_changes c
LEFT JOIN commercial.payments p ON p.invoice_id=c.invoice_id
LEFT JOIN commercial.refunds r ON r.payment_id=p.id
 AND r.client_request_key='plan-change-unapplied-' || c.id::text
WHERE c.status='paid_unapplied'
ORDER BY c.updated_at, c.id LIMIT ?
SQL, [(int) $raw], [ParameterType::INTEGER]);
        foreach ($rows as $row) {
            $output->writeln(json_encode($row, JSON_THROW_ON_ERROR));
        }
        $output->writeln(sprintf('Open compensation cases: %d shown', count($rows)));
        return Command::SUCCESS;
    }
}

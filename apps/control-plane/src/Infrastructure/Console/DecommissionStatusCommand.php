<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:deployments:decommission-status', description: 'Show safe deprovision operation status')]
final class DecommissionStatusCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('deployment-id', null, InputOption::VALUE_REQUIRED, 'Deployment UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('deployment-id');
        if ($id !== null && (!is_string($id) || !Uuid::isValid($id))) {
            throw new \InvalidArgumentException('Invalid deployment ID.');
        }
        $rows = $this->db->fetchAllAssociative("SELECT deployment_id::text AS \"deploymentId\",
            operation_type AS \"operationType\", current_step AS \"currentStep\",
            status, last_error_code AS \"lastErrorCode\"
            FROM commercial.deployment_operations WHERE operation_type = 'deprovision'
              AND (?::uuid IS NULL OR deployment_id = ?::uuid)
            ORDER BY created_at DESC LIMIT 100", [$id, $id]);
        $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}

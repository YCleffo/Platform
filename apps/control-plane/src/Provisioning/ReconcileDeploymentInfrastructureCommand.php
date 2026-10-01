<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:deployments:infrastructure:reconcile',
    description: 'Reconcile local dedicated deployment topology')]
final class ReconcileDeploymentInfrastructureCommand extends Command
{
    public function __construct(private readonly Connection $db,
        private readonly LocalDockerInfrastructureProvider $provider,
        private readonly LocalProvisioningSecretStore $secrets,
        private readonly ProvisioningRequestFactory $requests,
        private readonly string $environment) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('deployment-id', null, InputOption::VALUE_REQUIRED, 'Deployment UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('deployment-id');
        if (!is_string($id) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $id) !== 1
            || !in_array($this->environment, ['dev', 'test'], true)
            || (getenv('PROVISIONER_PROVIDER') ?: 'local-docker') !== 'local-docker') {
            $output->writeln('Deployment reconciliation is unavailable.');
            return Command::FAILURE;
        }
        try {
            $row = $this->db->fetchAssociative("SELECT d.id AS deployment_id, d.installation_id,
                d.mode, d.status AS deployment_status, d.archived_at, d.desired_release_id,
                d.core_db_secret_ref, r.version, r.channel, r.status AS release_status,
                r.oci_reference, r.image_digest
                FROM commercial.deployments d LEFT JOIN commercial.releases r ON r.id = d.desired_release_id
                WHERE d.id = ?", [$id]);
            if ($row === false || $row['mode'] !== 'dedicated'
                || !in_array($row['deployment_status'], ['active', 'suspended'], true)
                || $row['archived_at'] !== null) {
                throw new ProvisioningFailure('infrastructure.deployment_ineligible');
            }
            $stored = $this->secrets->existing($id);
            if ($row['core_db_secret_ref'] !== $stored['reference']) {
                throw new ProvisioningFailure('infrastructure.secret_storage_failed');
            }
            $this->provider->reconcile($this->requests->create($row, $stored, false));
            $output->writeln('Dedicated topology reconciled.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln($e instanceof ProvisioningFailure ? $e->machineCode : 'infrastructure.unavailable');
            return Command::FAILURE;
        }
    }
}

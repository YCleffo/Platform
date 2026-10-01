<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:deployments:release:reconcile', description: 'Safely record a proven legacy runtime release')]
final class ReconcileDeploymentReleaseCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly LocalDockerInfrastructureProvider $provider,
        private readonly LocalProvisioningSecretStore $secrets, private readonly ProvisioningRequestFactory $requests,
        private readonly string $environment) { parent::__construct(); }
    protected function configure(): void { $this->addOption('deployment-id', null, InputOption::VALUE_REQUIRED); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id=(string)$input->getOption('deployment-id');
        try {
            if (!in_array($this->environment,['dev','test'],true)) throw new ProvisioningFailure('infrastructure.provider_not_allowed');
            $row=$this->db->fetchAssociative("SELECT d.id AS deployment_id,d.installation_id,d.mode,d.status AS deployment_status,d.archived_at,
                d.core_db_secret_ref,d.desired_release_id,r.version,r.channel,r.status AS release_status,r.oci_reference,r.image_digest
                FROM commercial.deployments d JOIN commercial.releases r ON r.id=d.desired_release_id WHERE d.id=? FOR UPDATE",[$id]);
            if($row===false || $row['deployment_status']!=='active' || $row['archived_at']!==null || $row['mode']!=='dedicated' || $row['desired_release_id']===null)
                throw new ProvisioningFailure('infrastructure.deployment_ineligible');
            $stored=$this->secrets->existing($id); if($row['core_db_secret_ref']!==$stored['reference']) throw new ProvisioningFailure('infrastructure.secret_storage_failed');
            // This command is read-only with respect to containers.
            $this->provider->validateRelease($this->requests->create($row,$stored,false));
            $this->db->executeStatement('UPDATE commercial.deployments SET current_release_id=desired_release_id,updated_at=now() WHERE id=? AND current_release_id IS NULL',[$id]);
            $output->writeln('Deployment release reconciled.'); return Command::SUCCESS;
        } catch(\Throwable $e) { $output->writeln($e instanceof ProvisioningFailure?$e->machineCode:'infrastructure.unavailable'); return Command::FAILURE; }
    }
}

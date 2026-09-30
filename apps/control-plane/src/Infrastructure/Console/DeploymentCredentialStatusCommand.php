<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\M2m\DeploymentCredentialService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:deployments:credential:status', description: 'Show runtime credential expiry metadata')]
final class DeploymentCredentialStatusCommand extends Command
{
    public function __construct(private readonly DeploymentCredentialService $credentials) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('deployment-id', null, InputOption::VALUE_REQUIRED, 'Deployment UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $deploymentId = $input->getOption('deployment-id');
            if (!is_string($deploymentId) || !Uuid::isValid($deploymentId)) {
                throw new \DomainException('Invalid deployment ID.');
            }
            $credential = $this->credentials->activeRuntimeCredential($deploymentId);
            $output->writeln(json_encode(['credentialId' => $credential['credentialId'],
                'scopes' => $credential['scopes'], 'expiresAt' => $credential['expiresAt'],
                'daysUntilExpiry' => $credential['daysUntilExpiry']], JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('Deployment credential status unavailable.');
            return Command::FAILURE;
        }
    }
}

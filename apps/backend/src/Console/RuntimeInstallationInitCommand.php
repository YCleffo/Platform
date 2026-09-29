<?php

declare(strict_types=1);

namespace App\Console;

use App\PlatformRuntime\RuntimeInstallationBootstrap;
use DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'runtime:installation:init', description: 'Initialize the local installation from server configuration')]
final class RuntimeInstallationInitCommand extends Command
{
    public function __construct(private readonly RuntimeInstallationBootstrap $bootstrap) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $environment = (string) getenv('APP_ENV');
            $environment = match ($environment) {
                'dev' => 'development', 'prod' => 'production', default => $environment,
            };
            $id = $this->bootstrap->initialize([
                'installationId' => (string) getenv('PLATFORM_INSTALLATION_ID'),
                'mode' => (string) getenv('PLATFORM_DEPLOYMENT_MODE'),
                'environment' => $environment,
                'applicationVersion' => (string) getenv('APPLICATION_VERSION'),
                'releaseId' => (string) getenv('PLATFORM_RELEASE_ID'),
                'releaseChannel' => (string) getenv('PLATFORM_RELEASE_CHANNEL'),
            ]);
            $output->writeln(json_encode(['installationId' => $id], JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        } catch (DomainException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console;

use App\PlatformRuntime\RuntimeActivationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'runtime:deployment:activate', description: 'Initialize runtime, sync entitlement and report ready')]
final class RuntimeDeploymentActivateCommand extends Command
{
    public function __construct(private readonly RuntimeActivationService $activation) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = (string) getenv('APP_ENV');
        $environment = match ($environment) {
            'dev' => 'development', 'prod' => 'production', default => $environment,
        };
        try {
            $result = $this->activation->activate([
                'installationId' => (string) getenv('PLATFORM_INSTALLATION_ID'),
                'mode' => (string) getenv('PLATFORM_DEPLOYMENT_MODE'),
                'environment' => $environment,
                'applicationVersion' => (string) getenv('APPLICATION_VERSION'),
                'releaseId' => (string) getenv('PLATFORM_RELEASE_ID'),
                'releaseChannel' => (string) getenv('PLATFORM_RELEASE_CHANNEL'),
            ]);
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('Runtime deployment activation failed.');
            return Command::FAILURE;
        }
    }
}

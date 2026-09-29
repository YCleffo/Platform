<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Privacy\Application\ComplianceReadinessChecker;
use App\Environment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'privacy/compliance/check', description: 'Read-only technical privacy readiness check.')]
final class PrivacyComplianceCheckCommand extends Command
{
    public function __construct(private readonly ComplianceReadinessChecker $checker) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->checker->check(Environment::isProd());
        $output->writeln('technical readiness ' . $result['result']);
        foreach ($result['missingRequirements'] as $requirement) {
            $output->writeln('missing: ' . $requirement);
        }
        return $result['result'] === 'PASS' ? Command::SUCCESS : Command::FAILURE;
    }
}

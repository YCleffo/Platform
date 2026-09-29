<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Privacy\ControlPrivacyReadinessChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:privacy:check', description: 'Read-only Control Plane privacy configuration readiness')]
final class CheckControlPrivacyCommand extends Command
{
    public function __construct(private readonly ControlPrivacyReadinessChecker $checker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->checker->check();
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
        return $result['result'] === 'PASS' ? Command::SUCCESS : Command::FAILURE;
    }
}

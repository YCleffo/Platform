<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Privacy\ControlUserErasure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:privacy:erase', description: 'Execute approved Control User privacy lifecycle')]
final class EraseControlUserCommand extends Command
{
    public function __construct(private readonly ControlUserErasure $erasure)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('control-user-id', InputArgument::REQUIRED, 'Control User UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('control-user-id');
        if (!Uuid::isValid($id)) {
            $output->writeln('Invalid Control User UUID.');
            return Command::INVALID;
        }
        $result = $this->erasure->erase($id);
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
        return $result['status'] === 'blocked' ? Command::FAILURE : Command::SUCCESS;
    }
}

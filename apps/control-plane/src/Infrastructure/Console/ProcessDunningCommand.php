<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Billing\Application\ProcessRenewalDunning;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:billing:process-dunning', description: 'Process a bounded renewal dunning batch')]
final class ProcessDunningCommand extends Command
{
    public function __construct(private readonly ProcessRenewalDunning $dunning) { parent::__construct(); }
    protected function configure(): void { $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum renewals', '100'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('limit');
        if (!is_string($raw) || preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1 || (int) $raw > 1000) throw new \InvalidArgumentException('Limit must be between 1 and 1000.');
        $result = $this->dunning->process(new \DateTimeImmutable('now'), (int) $raw, Uuid::v7()->toRfc4122());
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}

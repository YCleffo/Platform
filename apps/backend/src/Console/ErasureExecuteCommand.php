<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\ErasureExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'privacy/erasure/execute', description: 'Execute one bounded erasure case batch.')]
final class ErasureExecuteCommand extends Command
{
    public function __construct(private readonly ErasureExecutor $executor) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('case', null, InputOption::VALUE_REQUIRED, 'Erasure case UUID');
        $this->addOption('executor-account', null, InputOption::VALUE_REQUIRED, 'Account UUID for accountability');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Items per batch (1 to 100)', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $caseId = $input->getOption('case');
        $accountId = $input->getOption('executor-account');
        $limit = $input->getOption('limit');
        $uuid = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di';
        if (!is_string($caseId) || preg_match($uuid, $caseId) !== 1
            || !is_string($accountId) || preg_match($uuid, $accountId) !== 1
            || !is_string($limit) || preg_match('/^[1-9][0-9]{0,2}$/D', $limit) !== 1
            || (int) $limit > 100) {
            $output->writeln('Invalid erasure command options.');
            return Command::INVALID;
        }
        try {
            $actor = new AuthenticatedIdentity($accountId, null, '', [], [], null, null);
            $result = $this->executor->executeBatch($actor, $caseId, (int) $limit);
        } catch (\Throwable) {
            $output->writeln('Erasure execution failed.');
            return Command::FAILURE;
        }
        foreach (['status', 'completed', 'blocked', 'failed', 'pending'] as $key) {
            $output->writeln($key . ': ' . $result[$key]);
        }
        return Command::SUCCESS;
    }
}

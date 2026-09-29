<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Privacy\Application\PrivacyCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'privacy/cleanup', description: 'Run one bounded privacy lifecycle batch.')]
final class PrivacyCleanupCommand extends Command
{
    public function __construct(private readonly PrivacyCleanupService $cleanup) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('category', null, InputOption::VALUE_REQUIRED,
            'Cleanup category: ' . implode(', ', PrivacyCleanupService::categories()));
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows (1 to 1000)', '100');
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Delete eligible rows (default is dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $category = $input->getOption('category');
        $limit = $input->getOption('limit');
        if (!is_string($category) || !in_array($category, PrivacyCleanupService::categories(), true)
            || !is_string($limit) || preg_match('/^[1-9][0-9]{0,3}$/D', $limit) !== 1
            || (int) $limit > 1000) {
            $output->writeln('Invalid privacy cleanup options.');
            return Command::INVALID;
        }
        try {
            $result = $this->cleanup->execute($category, (int) $limit,
                $input->getOption('execute') !== true);
        } catch (\Throwable) {
            $output->writeln('Privacy cleanup unavailable.');
            return Command::FAILURE;
        }
        $output->writeln('category: ' . $result['category']);
        $output->writeln('selected: ' . $result['selected']);
        $output->writeln('deleted: ' . $result['deleted']);
        $output->writeln('dryRun: ' . ($result['dryRun'] ? 'true' : 'false'));
        return Command::SUCCESS;
    }
}

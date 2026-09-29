<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Privacy\ControlPrivacyCleanup;
use ControlPlane\Privacy\ControlPrivacyRetentionPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:security:cleanup', description: 'Bounded Control Plane privacy lifecycle cleanup')]
final class CleanupControlSecurityCommand extends Command
{
    public function __construct(private readonly ControlPrivacyCleanup $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('category', null, InputOption::VALUE_REQUIRED, 'Configured category, or all', 'all');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records per category', '100');
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Delete eligible records; default is dry-run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requested = (string) $input->getOption('category');
        $categories = $requested === 'all' ? ControlPrivacyRetentionPolicy::categories() : [$requested];
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $output->writeln('Invalid limit (1..1000).');
            return Command::INVALID;
        }
        try {
            foreach ($categories as $category) {
                $output->writeln(json_encode($this->cleanup->run($category, $limit, !$input->getOption('execute')), JSON_THROW_ON_ERROR));
            }
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $output->writeln($exception->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

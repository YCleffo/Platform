<?php

declare(strict_types=1);

namespace App\Storage\Console;

use App\Storage\Infrastructure\LegacyObjectMigration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateObjectsCommand extends Command
{
    public function __construct(private readonly LegacyObjectMigration $migration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('storage:migrate-objects')->setDescription('Verify legacy source bytes and migrate referenced objects into S3.')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'storage or education', 'storage')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Explicit legacy source directory')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Upload and verify. The default is dry-run.')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write a private JSON migration report to this path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $report = $this->migration->run(
                (string) $input->getOption('module'),
                (string) $input->getOption('source'),
                $input->getOption('apply') === true,
            );
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            $path = $input->getOption('report');
            if (is_string($path) && $path !== '') {
                $handle = fopen($path, 'xb');
                if ($handle === false) {
                    throw new \RuntimeException('The report path must be a new writable file.');
                }
                try {
                    if (fwrite($handle, $json) !== strlen($json)) {
                        throw new \RuntimeException('Could not write the report.');
                    }
                } finally {
                    fclose($handle);
                }
                chmod($path, 0600);
                $output->writeln($report['success'] ? 'Object migration verified. Source bytes were preserved.' : 'Migration failed. Inspect the private report.');
            } else {
                $output->writeln($json);
            }
            return $report['success'] ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable) {
            $output->writeln('Migration failed. Verify the explicit source directory, schema, S3 connectivity and report path.');
            return Command::FAILURE;
        }
    }
}

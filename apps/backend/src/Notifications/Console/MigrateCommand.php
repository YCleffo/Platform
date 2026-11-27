<?php

declare(strict_types=1);

namespace App\Notifications\Console;

use App\Shared\Database\SchemaMigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

#[AsCommand(name: 'notifications:migrate', description: 'Apply Universal Notifications schema migrations')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = new SchemaMigrationRunner(
            $this->db,
            'notifications',
            dirname(__DIR__, 3) . '/migrations/notifications',
            1846202703,
        );
        $runner->apply();
        $output->writeln('Notifications migrations applied.');
        return Command::SUCCESS;
    }
}

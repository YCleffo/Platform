<?php

declare(strict_types=1);

namespace App\Realtime\Console;

use App\Realtime\Infrastructure\WebSocketServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'realtime:serve', description: 'Run WebSocket & Redis Pub/Sub realtime server')]
final class ServeCommand extends Command
{
    public function __construct(private readonly WebSocketServer $server)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Bind host', '0.0.0.0')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Bind port', '8084');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');

        $this->server->serve($host, $port, static function (string $h, int $p) use ($output): void {
            $output->writeln(sprintf('[realtime] WebSocket server listening on ws://%s:%d/ws', $h, $p));
        });

        return Command::SUCCESS;
    }
}

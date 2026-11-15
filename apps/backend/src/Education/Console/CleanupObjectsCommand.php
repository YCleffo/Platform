<?php

declare(strict_types=1);

namespace App\Education\Console;

use App\Education\Infrastructure\EducationObjectCleanup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CleanupObjectsCommand extends Command
{
    public function __construct(private readonly EducationObjectCleanup $cleanup) { parent::__construct(); }
    protected function configure(): void { $this->setName('education:cleanup-objects')->setDescription('Reconcile expired transfers and delete unreferenced Education S3 objects.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode($this->cleanup->run(), JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\RetentionDecisionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'privacy/retention/evaluate', description: 'Evaluate one bounded retention batch without changing resources.')]
final class RetentionEvaluateCommand extends Command
{
    public function __construct(private readonly RetentionCandidateContributorRegistry $contributors,
        private readonly RetentionDecisionService $decisions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum candidates (1 to 1000)', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('limit');
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,3}$/D', $raw) !== 1
            || (int) $raw > 1000) {
            $output->writeln('Invalid batch limit.');
            return Command::INVALID;
        }
        try {
            $candidates = $this->contributors->collect((int) $raw);
            $groups = [];
            foreach ($candidates as $candidate) {
                $groups[implode(':', [$candidate->subjectPersonId, $candidate->resourceType,
                    $candidate->resourceId])][] = $candidate;
            }
            $counts = [];
            foreach ($groups as $group) {
                foreach ($this->decisions->evaluateResource($group)['decisions'] as $decision) {
                    $counts[$decision->code] = ($counts[$decision->code] ?? 0) + 1;
                }
            }
        } catch (\Throwable) {
            $output->writeln('Retention evaluation failed.');
            return Command::FAILURE;
        }
        ksort($counts);
        $output->writeln('Candidates evaluated: ' . count($candidates));
        foreach ($counts as $code => $count) {
            $output->writeln($code . ': ' . $count);
        }
        return Command::SUCCESS;
    }
}

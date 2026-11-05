<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Auth\Application\RotateAccountPassword;
use App\Core\Client\Application\ClientContextResolver;
use HttpSoft\Message\ServerRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'core:rotate-password', description: 'Rotate one human account password and revoke its old sessions and challenges.')]
final class RotateAccountPasswordCommand extends Command
{
    public function __construct(private readonly RotateAccountPassword $rotation, private readonly ClientContextResolver $contexts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('account-id', null, InputOption::VALUE_REQUIRED, 'Existing Core human account UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('account-id');
        if (!is_string($id) || !\App\Api\Shared\Uuid::isValid($id)) {
            $output->writeln('<error>A valid --account-id is required.</error>');
            return Command::INVALID;
        }
        $password = getenv('CORE_ROTATED_PASSWORD');
        if ((!is_string($password) || $password === '') && $input->isInteractive()) {
            $helper = $this->getHelper('question');
            if ($helper instanceof QuestionHelper) {
                $answer = $helper->ask($input, $output, (new Question('New password: '))->setHidden(true)->setHiddenFallback(false));
                $password = is_string($answer) ? $answer : '';
            }
        }
        if (!is_string($password) || $password === '') {
            $output->writeln('<error>Supply CORE_ROTATED_PASSWORD or use the hidden interactive prompt.</error>');
            return Command::INVALID;
        }
        try {
            $context = $this->contexts->resolve(new ServerRequest(serverParams: ['REMOTE_ADDR' => '127.0.0.1'], headers: ['User-Agent' => 'Platform operator credential rotation']));
            $this->rotation->execute($id, $password, $context);
        } catch (\Throwable) {
            $output->writeln('<error>Password rotation failed; no secret values are printed.</error>');
            return Command::FAILURE;
        }
        $output->writeln('Password rotated; old sessions and challenges revoked; MFA preserved.');
        return Command::SUCCESS;
    }
}

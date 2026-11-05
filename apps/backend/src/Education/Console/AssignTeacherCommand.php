<?php

declare(strict_types=1);

namespace App\Education\Console;

use App\Core\Account\Application\GetAccount;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Education\Application\EducationStore;
use App\Education\Application\Input;
use App\Education\Application\Mutation;
use App\Education\Application\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AssignTeacherCommand extends Command
{
    public function __construct(private readonly GetAccount $accounts, private readonly OrganizationRepository $organizations,
        private readonly PermissionService $permissions, private readonly EducationStore $store, private readonly Mutation $mutations)
    { parent::__construct('education:teacher:assign'); }

    protected function configure(): void
    {
        foreach (['actor', 'account', 'organization'] as $name) $this->addOption($name, null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actorId = Input::uuid($input->getOption('actor')); $accountId = Input::uuid($input->getOption('account'));
        $organizationId = Input::uuid($input->getOption('organization'));
        $actor = $this->accounts->execute($actorId); $account = $this->accounts->execute($accountId);
        if ($actor === null || $actor->personId === null || $actor->status !== 'active'
            || !$this->permissions->hasPermission($actorId, 'core.rbac.manage') || $account === null
            || $account->accountType !== 'human' || $account->personId === null || $account->status !== 'active'
            || !$this->organizations->activeMember($account->personId, $organizationId)) {
            $output->writeln('<error>Active Core administrator, target account and organization membership are required.</error>');
            return Command::FAILURE;
        }
        $scope = new Scope($organizationId, $actorId, $actor->personId, 'en-US', 'UTC');
        $this->mutations->run($scope, 'assign_teacher', function () use ($organizationId, $accountId, $account): array {
            $this->store->grantTeacher($organizationId, $accountId, $account->personId);
            return ['id' => $accountId];
        });
        $output->writeln('Teacher assignment saved. The education.teacher Core RBAC role is granted separately through Core administration.');
        return Command::SUCCESS;
    }
}

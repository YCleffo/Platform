<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Auth\Application\AuthIdentifierNormalizer;
use App\Core\Auth\Application\PasswordHasherInterface;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Time\Domain\TimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\Question;
use Yiisoft\Db\Connection\ConnectionInterface;

#[AsCommand(
    name: 'core:bootstrap-superadmin',
    description: 'Creates the first active Core super administrator.',
)]
final class BootstrapSuperadminCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuthIdentifierNormalizer $normalizer,
        private readonly PasswordHasherInterface $passwords,
        private readonly SupportedLocales $supportedLocales,
        private readonly AuditService $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Verified primary email address')
            ->addOption('given-name', null, InputOption::VALUE_REQUIRED, 'Person given name')
            ->addOption('family-name', null, InputOption::VALUE_REQUIRED, 'Person family name')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'BCP 47 locale', 'ru-RU')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'IANA timezone', 'UTC');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $this->requiredOption($input, 'email');
        $givenName = $this->requiredOption($input, 'given-name');
        $familyName = $this->requiredOption($input, 'family-name');
        if ($email === null || $givenName === null || $familyName === null) {
            $output->writeln('<error>Options --email, --given-name and --family-name are required.</error>');
            return Command::INVALID;
        }

        $password = getenv('CORE_BOOTSTRAP_PASSWORD');
        if (!is_string($password) || $password === '') {
            if (!$input->isInteractive()) {
                $output->writeln('<error>CORE_BOOTSTRAP_PASSWORD is required in non-interactive mode.</error>');
                return Command::INVALID;
            }
            $question = (new Question('Password: '))->setHidden(true)->setHiddenFallback(false);
            $helper = $this->getHelper('question');
            if (!$helper instanceof QuestionHelper) {
                throw new \RuntimeException('Console question helper is unavailable.');
            }
            $answer = $helper->ask($input, $output, $question);
            $password = is_string($answer) ? $answer : '';
        }
        if (mb_strlen($password) < 12) {
            $output->writeln('<error>Password must contain at least 12 characters.</error>');
            return Command::INVALID;
        }

        try {
            $normalizedEmail = $this->normalizer->normalize('email', $email);
            $requestedLocale = $this->requiredOption($input, 'locale') ?? 'ru-RU';
            $supportedLocale = $this->supportedLocales->match($requestedLocale);
            if ($supportedLocale === null) {
                throw new \InvalidArgumentException();
            }
            $locale = $supportedLocale->tag;
            $timezone = $this->requiredOption($input, 'timezone') ?? 'UTC';
            if ($timezone === '') {
                throw new \InvalidArgumentException();
            }
            $timezone = (new TimeZone($timezone))->identifier;
        } catch (\Throwable) {
            $output->writeln('<error>Email, locale, or timezone is invalid.</error>');
            return Command::INVALID;
        }

        $hash = $this->passwords->hash($password);
        $algorithm = password_get_info($hash)['algoName'] ?? 'argon2id';

        try {
            $accountId = $this->db->transaction(function () use (
                $email,
                $normalizedEmail,
                $givenName,
                $familyName,
                $locale,
                $timezone,
                $hash,
                $algorithm,
            ): string {
                $roleId = $this->db->createCommand(
                    "SELECT id::text FROM core.roles WHERE code = 'core.superadmin' FOR UPDATE",
                )->queryScalar();
                if (!is_string($roleId)) {
                    throw new \RuntimeException('The core.superadmin role is missing.');
                }
                $exists = $this->db->createCommand(
                    <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                        FROM core.account_roles ar
                        INNER JOIN core.accounts a ON a.id = ar.account_id
                        WHERE ar.role_id = CAST(:role_id AS uuid)
                          AND (ar.expires_at IS NULL OR ar.expires_at > now())
                          AND a.status = 'active'
                          AND a.disabled_at IS NULL
                          AND a.archived_at IS NULL
                    )
                    SQL,
                    [':role_id' => $roleId],
                )->queryScalar();
                if ($exists === true || $exists === 't' || $exists === 1) {
                    throw new \DomainException('An active super administrator already exists.');
                }

                $personId = $this->db->createCommand(
                    <<<'SQL'
                    INSERT INTO core.persons (given_name, family_name)
                    VALUES (:given_name, :family_name)
                    RETURNING id::text
                    SQL,
                    [':given_name' => $givenName, ':family_name' => $familyName],
                )->queryScalar();
                if (!is_string($personId)) {
                    throw new \RuntimeException('Person creation failed.');
                }
                $accountId = $this->db->createCommand(
                    <<<'SQL'
                    INSERT INTO core.accounts (person_id, account_type, status, label, locale, timezone, approved_at)
                    VALUES (CAST(:person_id AS uuid), 'human', 'active', :label, :locale, :timezone, now())
                    RETURNING id::text
                    SQL,
                    [
                        ':person_id' => $personId,
                        ':label' => trim($givenName . ' ' . $familyName),
                        ':locale' => $locale,
                        ':timezone' => $timezone,
                    ],
                )->queryScalar();
                if (!is_string($accountId)) {
                    throw new \RuntimeException('Account creation failed.');
                }
                $this->db->createCommand(
                    <<<'SQL'
                    INSERT INTO core.auth_identifiers (
                        account_id, identifier_type, value, normalized_value,
                        is_primary, can_login, can_recover, verified_at
                    )
                    VALUES (
                        CAST(:account_id AS uuid), 'email', :value, :normalized,
                        true, true, true, now()
                    )
                    SQL,
                    [':account_id' => $accountId, ':value' => $email, ':normalized' => $normalizedEmail],
                )->execute();
                $this->db->createCommand(
                    <<<'SQL'
                    INSERT INTO core.auth_password_credentials (
                        account_id, password_hash, password_algorithm
                    )
                    VALUES (CAST(:account_id AS uuid), :hash, :algorithm)
                    SQL,
                    [':account_id' => $accountId, ':hash' => $hash, ':algorithm' => (string) $algorithm],
                )->execute();
                $this->db->createCommand(
                    <<<'SQL'
                    INSERT INTO core.account_roles (account_id, role_id)
                    VALUES (CAST(:account_id AS uuid), CAST(:role_id AS uuid))
                    SQL,
                    [':account_id' => $accountId, ':role_id' => $roleId],
                )->execute();
                $this->audit->record(new WriteAuditEventCommand(
                    actorType: 'system',
                    actorAccountId: null,
                    action: 'core.bootstrap.superadmin_created',
                    resourceType: 'core.account',
                    resourceId: $accountId,
                    metadata: ['identifierType' => 'email'],
                ));

                return $accountId;
            });
        } catch (\DomainException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Super administrator created: %s</info>', $accountId));
        return Command::SUCCESS;
    }

    private function requiredOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

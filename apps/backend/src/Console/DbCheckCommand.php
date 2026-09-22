<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

#[AsCommand(
    name: 'db:check',
    description: 'Checks PostgreSQL connection and Core schema.',
)]
final class DbCheckCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
        /** @var array<string, array<string, ?string>> */
        private readonly array $externalProviderConfiguration = [],
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $database = $this->db
            ->createCommand('SELECT current_database()')
            ->queryScalar();

        $coreTables = $this->db
            ->createCommand(
                <<<'SQL'
                SELECT count(*)
                FROM information_schema.tables
                WHERE table_schema = 'core'
                  AND table_type = 'BASE TABLE'
                SQL,
            )
            ->queryScalar();

        $internationalSecurityColumns = $this->db
            ->createCommand(
                <<<'SQL'
                SELECT count(*)
                FROM information_schema.columns
                WHERE table_schema = 'core'
                  AND (
                    (table_name = 'accounts' AND column_name IN ('locale', 'timezone'))
                    OR
                    (table_name = 'auth_sessions' AND column_name IN (
                        'browser_name', 'browser_version', 'os_name', 'os_version',
                        'device_type', 'device_model', 'client_locale', 'client_timezone',
                        'client_instance_id', 'device_fingerprint_hash', 'geo_country_code',
                        'geo_region', 'geo_city', 'geo_timezone'
                    ))
                  )
                SQL,
            )
            ->queryScalar();

        $authRuntimeColumns = $this->db
            ->createCommand(
                <<<'SQL'
                SELECT count(*)
                FROM information_schema.columns
                WHERE table_schema = 'core'
                  AND table_name = 'auth_sessions'
                  AND column_name = 'access_expires_at'
                SQL,
            )
            ->queryScalar();

        $externalMfaColumns = $this->db->createCommand(
            <<<'SQL'
            SELECT count(*)
            FROM information_schema.columns
            WHERE table_schema = 'core'
              AND (
                (table_name = 'auth_totp_factors' AND column_name IN (
                    'last_used_counter', 'secret_key_version', 'setup_expires_at'
                ))
                OR (table_name = 'auth_recovery_codes' AND column_name = 'revoked_at')
              )
            SQL,
        )->queryScalar();
        $enabledProviders = $this->db->createCommand(
            "SELECT code FROM core.auth_providers WHERE enabled = true AND code IN ('google', 'telegram') ORDER BY code",
        )->queryColumn();

        $output->writeln("Database: {$database}");
        $output->writeln("Core tables: {$coreTables}");
        $output->writeln("International/security columns: {$internationalSecurityColumns}");
        $output->writeln("Authentication runtime columns: {$authRuntimeColumns}");
        $output->writeln("External identity/MFA columns: {$externalMfaColumns}");

        if (!is_string($database) || $database === '') {
            $output->writeln(
                '<error>Unable to determine the current database.</error>',
            );

            return Command::FAILURE;
        }

        if ((int) $coreTables !== 23) {
            $output->writeln(
                '<error>Expected exactly 23 Core tables.</error>',
            );

            return Command::FAILURE;
        }

        if ((int) $internationalSecurityColumns !== 16) {
            $output->writeln(
                '<error>Migration 010 international/security columns are incomplete.</error>',
            );

            return Command::FAILURE;
        }

        if ((int) $authRuntimeColumns !== 1) {
            $output->writeln(
                '<error>Migration 011 authentication runtime columns are incomplete.</error>',
            );

            return Command::FAILURE;
        }

        if ((int) $externalMfaColumns !== 4) {
            $output->writeln('<error>Migration 013 external identity/MFA columns are incomplete.</error>');
            return Command::FAILURE;
        }
        foreach ($enabledProviders as $provider) {
            $configuration = $this->externalProviderConfiguration[(string) $provider] ?? [];
            if (($configuration['clientId'] ?? null) === null
                || ($configuration['clientSecret'] ?? null) === null
                || ($configuration['redirectUri'] ?? null) === null
            ) {
                $output->writeln(sprintf(
                    '<error>Enabled provider "%s" has incomplete environment configuration.</error>',
                    (string) $provider,
                ));
                return Command::FAILURE;
            }
        }

        $output->writeln(
            '<info>PostgreSQL connection and Core schema are OK.</info>',
        );

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Payment\Provider\YooKassa\EnvPaymentProviderSecretResolver;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:payments:providers:yookassa:configure', description: 'Configure YooKassa merchant metadata and an environment secret reference')]
final class ConfigureYooKassaCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('shop-id', null, InputOption::VALUE_REQUIRED, 'YooKassa shopId')
            ->addOption('secret-ref', null, InputOption::VALUE_REQUIRED, 'Secret reference in env:VARIABLE_NAME form');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = $input->getOption('shop-id');
        $secretRef = $input->getOption('secret-ref');
        if (!is_string($shopId) || !is_string($secretRef)) {
            throw new \InvalidArgumentException('Both --shop-id and --secret-ref are required.');
        }
        $shopId = IdentifierPolicy::opaqueKey($shopId);
        $secretRef = EnvPaymentProviderSecretResolver::validateReference($secretRef);
        $id = $this->connection->transactional(function (Connection $connection) use ($shopId, $secretRef): string {
            $connection->executeQuery("SELECT id FROM commercial.payment_provider_accounts WHERE provider_code = 'yookassa' FOR UPDATE");
            $connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status = 'disabled', updated_at = now() WHERE provider_code = 'yookassa' AND status = 'active' AND external_account_id IS DISTINCT FROM ?", [$shopId]);
            $matches = $connection->fetchFirstColumn("SELECT id FROM commercial.payment_provider_accounts WHERE provider_code = 'yookassa' AND external_account_id = ? ORDER BY id", [$shopId]);
            if (count($matches) > 1) {
                throw new \DomainException('Matching YooKassa provider configuration is ambiguous.');
            }
            $id = $matches[0] ?? null;
            if (is_string($id)) {
                $connection->executeStatement("UPDATE commercial.payment_provider_accounts SET secret_ref = ?, status = 'active', updated_at = now() WHERE id = ?", [$secretRef, $id]);

                return $id;
            }
            $id = $connection->fetchOne("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref, status) VALUES ('yookassa', ?, ?, 'active') RETURNING id", [$shopId, $secretRef]);
            if (!is_string($id)) {
                throw new \LogicException('Configured provider account identifier is unavailable.');
            }

            return $id;
        });
        $output->writeln('Configured YooKassa provider account: ' . $id);

        return Command::SUCCESS;
    }
}

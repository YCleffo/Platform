<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Payment\Provider\YooKassa\EnvPaymentProviderSecretResolver;
use ControlPlane\Payment\Provider\YooKassa\YooKassaFiscalPolicy;
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
            ->addOption('secret-ref', null, InputOption::VALUE_REQUIRED, 'Secret reference in env:VARIABLE_NAME form')
            ->addOption('receipt-mode', null, InputOption::VALUE_REQUIRED, 'disabled or yookassa', 'disabled')
            ->addOption('vat-code', null, InputOption::VALUE_REQUIRED, 'YooKassa VAT code (1-12)')
            ->addOption('payment-subject', null, InputOption::VALUE_REQUIRED, 'Receipt payment subject')
            ->addOption('payment-mode', null, InputOption::VALUE_REQUIRED, 'Receipt payment mode');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = $input->getOption('shop-id');
        $secretRef = $input->getOption('secret-ref');
        $receiptMode = $input->getOption('receipt-mode');
        $vatCode = $input->getOption('vat-code');
        $paymentSubject = $input->getOption('payment-subject');
        $paymentMode = $input->getOption('payment-mode');
        if (!is_string($shopId) || !is_string($secretRef)) {
            throw new \InvalidArgumentException('Both --shop-id and --secret-ref are required.');
        }
        if ($receiptMode === 'disabled') {
            if ($vatCode !== null || $paymentSubject !== null || $paymentMode !== null) {
                throw new \InvalidArgumentException('Disabled receipt mode does not accept fiscal options.');
            }
            $vat = null;
            $subject = null;
            $mode = null;
        } elseif ($receiptMode === 'yookassa') {
            if (!is_string($vatCode) || preg_match('/\A(?:[1-9]|1[0-2])\z/D', $vatCode) !== 1 || !is_string($paymentSubject) || !is_string($paymentMode)) {
                throw new \InvalidArgumentException('YooKassa receipts require supported fiscal options.');
            }
            $vat = (int) $vatCode;
            $subject = $paymentSubject;
            $mode = $paymentMode;
            YooKassaFiscalPolicy::validateValues($vat, $subject, $mode);
        } else {
            throw new \InvalidArgumentException('Receipt mode must be disabled or yookassa.');
        }
        $shopId = IdentifierPolicy::opaqueKey($shopId);
        $secretRef = EnvPaymentProviderSecretResolver::validateReference($secretRef);
        $id = $this->connection->transactional(function (Connection $connection) use ($shopId, $secretRef, $receiptMode, $vat, $subject, $mode): string {
            $connection->executeQuery("SELECT id FROM commercial.payment_provider_accounts WHERE provider_code = 'yookassa' FOR UPDATE");
            $connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status = 'disabled', updated_at = now() WHERE provider_code = 'yookassa' AND status = 'active' AND external_account_id IS DISTINCT FROM ?", [$shopId]);
            $matches = $connection->fetchFirstColumn("SELECT id FROM commercial.payment_provider_accounts WHERE provider_code = 'yookassa' AND external_account_id = ? ORDER BY id", [$shopId]);
            if (count($matches) > 1) {
                throw new \DomainException('Matching YooKassa provider configuration is ambiguous.');
            }
            $id = $matches[0] ?? null;
            if (is_string($id)) {
                $connection->executeStatement("UPDATE commercial.payment_provider_accounts SET secret_ref = ?, receipt_mode = ?, receipt_vat_code = ?, receipt_payment_subject = ?, receipt_payment_mode = ?, status = 'active', updated_at = now() WHERE id = ?", [$secretRef, $receiptMode, $vat, $subject, $mode, $id]);

                return $id;
            }
            $id = $connection->fetchOne("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref, receipt_mode, receipt_vat_code, receipt_payment_subject, receipt_payment_mode, status) VALUES ('yookassa', ?, ?, ?, ?, ?, ?, 'active') RETURNING id", [$shopId, $secretRef, $receiptMode, $vat, $subject, $mode]);
            if (!is_string($id)) {
                throw new \LogicException('Configured provider account identifier is unavailable.');
            }

            return $id;
        });
        $output->writeln('Configured YooKassa provider account: ' . $id);

        return Command::SUCCESS;
    }
}

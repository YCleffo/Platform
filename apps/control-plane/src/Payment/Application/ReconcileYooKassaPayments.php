<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\PaymentProviderGateway;
use ControlPlane\Payment\Provider\YooKassa\YooKassaProviderAccountLocator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ReconcileYooKassaPayments
{
    public function __construct(
        private Connection $connection,
        private PaymentProviderGateway $gateway,
        private ProcessAuthoritativeProviderPayment $processor,
    ) {
    }

    /** @return array{selected: int, completed: int, pending: int, failed: int} */
    public function reconcile(int $limit, string $correlationId): array
    {
        if ($limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid reconciliation request.');
        }
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT pa.provider_payment_id, ppa.id, ppa.provider_code, ppa.external_account_id, ppa.secret_ref
FROM commercial.payment_attempts pa
JOIN commercial.payment_provider_accounts ppa ON ppa.id = pa.provider_account_id
WHERE pa.status = 'pending' AND pa.provider_payment_id IS NOT NULL AND ppa.provider_code = 'yookassa'
ORDER BY pa.created_at, pa.id
LIMIT ?
SQL, [$limit], [\Doctrine\DBAL\ParameterType::INTEGER]);
        $result = ['selected' => count($rows), 'completed' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            try {
                if (!is_string($row['provider_payment_id'] ?? null)) {
                    throw new \LogicException('Provider payment reference is unavailable.');
                }
                $account = YooKassaProviderAccountLocator::fromRow($row);
                $payment = $this->gateway->getPayment($account, $row['provider_payment_id']);
                $processed = $this->processor->process($account, $payment, $correlationId);
                if ($processed['changed']) {
                    ++$result['completed'];
                } else {
                    ++$result['pending'];
                }
            } catch (PaymentProviderException|\DomainException) {
                ++$result['failed'];
            }
        }

        return $result;
    }
}

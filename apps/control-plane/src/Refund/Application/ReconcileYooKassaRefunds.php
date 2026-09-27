<?php

declare(strict_types=1);

namespace ControlPlane\Refund\Application;

use ControlPlane\Payment\Provider\PaymentProviderException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class ReconcileYooKassaRefunds
{
    public function __construct(private Connection $connection, private SubmitYooKassaRefund $submission)
    {
    }

    /** @return array{selected: int, completed: int, pending: int, failed: int} */
    public function reconcile(int $limit, string $correlationId): array
    {
        if ($limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid refund reconciliation request.');
        }
        $ids = $this->connection->fetchFirstColumn("SELECT r.id FROM commercial.refunds r JOIN commercial.payment_provider_accounts ppa ON ppa.id = r.provider_account_id WHERE r.status = 'pending' AND ppa.provider_code = 'yookassa' ORDER BY r.created_at, r.id LIMIT ?", [$limit], [ParameterType::INTEGER]);
        $result = ['selected' => count($ids), 'completed' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            try {
                if (!is_string($id)) {
                    throw new \LogicException('Refund identifier is unavailable.');
                }
                if ($this->submission->submit($id, $correlationId)) {
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

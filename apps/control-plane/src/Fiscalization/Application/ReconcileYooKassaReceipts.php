<?php

declare(strict_types=1);

namespace ControlPlane\Fiscalization\Application;

use ControlPlane\Payment\Provider\PaymentProviderException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class ReconcileYooKassaReceipts
{
    public function __construct(private Connection $connection, private SubmitYooKassaReceipt $submission)
    {
    }

    /** @return array{selected: int, completed: int, pending: int, failed: int} */
    public function reconcile(int $limit, string $correlationId): array
    {
        if ($limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid receipt reconciliation request.');
        }
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM commercial.receipts WHERE status = 'pending' ORDER BY created_at, id LIMIT ?", [$limit], [ParameterType::INTEGER]);
        $result = ['selected' => count($ids), 'completed' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            try {
                if (!is_string($id)) {
                    throw new \LogicException('Receipt identifier is unavailable.');
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

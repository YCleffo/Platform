<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Privacy\ControlUserErasure;

final class PrivacyFinancialTest extends InvoiceTestCase
{
    public function testInvoiceSurvivesPartialUserErasure(): void
    {
        [$owner, $account, $profile, , , $subscription] = $this->invoiceContext('privacy-erasure');
        $secondOwner = $this->user();
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$secondOwner, $account]);
        $invoice = $this->insertDraftInvoice($account, $subscription, $profile);

        $result = (new ControlUserErasure($this->connection, true, 0, 0))->erase($owner);
        self::assertSame('partially_completed', $result['status']);
        self::assertSame('financial_evidence_retained', $result['reason_code']);
        self::assertSame(1, $result['financial_evidence_count']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoices WHERE id = ?', [$invoice]));
        self::assertSame('disabled', $this->connection->fetchOne('SELECT status FROM commercial.control_users WHERE id = ?', [$owner]));
    }
}

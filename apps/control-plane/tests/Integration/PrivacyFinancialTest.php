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
        $originalEmail = $this->connection->fetchOne('SELECT email FROM commercial.control_users WHERE id = ?', [$owner]);

        $result = (new ControlUserErasure($this->connection, true, 0, 0))->erase($owner);
        self::assertSame('partially_completed', $result['status']);
        self::assertSame('financial_evidence_retained', $result['reason_code']);
        self::assertSame(1, $result['financial_evidence_count']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoices WHERE id = ?', [$invoice]));
        self::assertSame('disabled', $this->connection->fetchOne('SELECT status FROM commercial.control_users WHERE id = ?', [$owner]));
        $user = $this->connection->fetchAssociative('SELECT email, email_normalized, password_hash FROM commercial.control_users WHERE id = ?', [$owner]);
        self::assertSame('erased-' . $owner . '@privacy.invalid', $user['email']);
        self::assertSame($user['email'], $user['email_normalized']);
        self::assertNotSame($originalEmail, $user['email']);
        self::assertSame('!erased-disabled!', $user['password_hash']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.control_sessions WHERE control_user_id = ?', [$owner]));
    }
}

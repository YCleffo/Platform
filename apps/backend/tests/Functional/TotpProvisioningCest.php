<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Account\Application\AccountRepository;
use App\Core\Auth\Application\IdentifierRepository;
use App\Core\Auth\Application\TotpProvisioningPolicy;
use App\Core\Auth\Application\TotpService;
use App\Core\Security\Application\IdentifierDigestService;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use InvalidArgumentException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class TotpProvisioningCest
{
    public function provisioningLabelPrioritizesVerifiedEmailAndSanitizesInjection(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $identifiers = $container->get(IdentifierRepository::class);
            $accounts = $container->get(AccountRepository::class);
            $digests = $container->get(IdentifierDigestService::class);
            $totp = $container->get(TotpService::class);
            $policy = new TotpProvisioningPolicy($identifiers, $accounts, $digests, 'Platform');

            // 1. Primary verified email with special characters (+ and @)
            $accountId = (string) $db->createCommand(
                "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'Test Label') RETURNING id::text",
            )->queryScalar();
            $email = 'user+test@example.com';
            $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_identifiers (
                    account_id, identifier_type, value, normalized_value,
                    is_primary, can_login, can_recover, verified_at
                ) VALUES (CAST(:account_id AS uuid), 'email', :email, :email, true, true, true, now())
                SQL,
                [':account_id' => $accountId, ':email' => $email],
            )->execute();

            $label = $policy->accountLabel($accountId);
            assertSame('user+test@example.com', $label);

            $uri = $totp->provisioningUri('Platform', $label, 'JBSWY3DPEHPK3PXP');
            assertSame(
                'otpauth://totp/Platform:user%2Btest%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Platform&algorithm=SHA1&digits=6&period=30',
                $uri,
            );

            // 2. Account label fallback with colon and control chars (anti-spoofing)
            $spoofAccountId = (string) $db->createCommand(
                "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', :label) RETURNING id::text",
                [':label' => "Spoofed:Issuer\x01\x1Fattack"],
            )->queryScalar();
            $spoofedLabel = $policy->accountLabel($spoofAccountId);
            assertSame('SpoofedIssuerattack', $spoofedLabel);
            assertFalse(str_contains($spoofedLabel, ':'));
            assertFalse(str_contains($spoofedLabel, "\x01"));
            assertFalse(str_contains($spoofedLabel, "\x1F"));

            // 3. Opaque keyed digest fallback when no email or label
            $emptyAccountId1 = (string) $db->createCommand(
                "INSERT INTO core.accounts (account_type, status) VALUES ('service', 'active') RETURNING id::text",
            )->queryScalar();
            $emptyAccountId2 = (string) $db->createCommand(
                "INSERT INTO core.accounts (account_type, status) VALUES ('service', 'active') RETURNING id::text",
            )->queryScalar();

            $emptyLabel1 = $policy->accountLabel($emptyAccountId1);
            $emptyLabel1Repeat = $policy->accountLabel($emptyAccountId1);
            $emptyLabel2 = $policy->accountLabel($emptyAccountId2);

            assertTrue(str_starts_with($emptyLabel1, 'account-'));
            assertSame(24, strlen($emptyLabel1)); // 'account-' (8) + 16 hex chars
            // Irreversible: raw UUID and raw hex representation must NOT be in the label
            assertFalse(str_contains($emptyLabel1, $emptyAccountId1));
            assertFalse(str_contains($emptyLabel1, str_replace('-', '', $emptyAccountId1)));
            // Deterministic stability
            assertSame($emptyLabel1, $emptyLabel1Repeat);
            // Different account produces different fallback label
            assertNotSame($emptyLabel1, $emptyLabel2);

            // 4. Issuer sanitization & validation (colons and control characters normalized/forbidden)
            $sanitizedIssuerPolicy = new TotpProvisioningPolicy($identifiers, $accounts, $digests, "Platform:Internal\x01\x1F");
            assertSame('PlatformInternal', $sanitizedIssuerPolicy->issuer);
            assertFalse(str_contains($sanitizedIssuerPolicy->issuer, ':'));

            try {
                new TotpProvisioningPolicy($identifiers, $accounts, $digests, '');
                $tester->fail('Empty issuer must be rejected.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
            try {
                new TotpProvisioningPolicy($identifiers, $accounts, $digests, ':::');
                $tester->fail('Issuer with only colons must be rejected.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
            try {
                new TotpProvisioningPolicy($identifiers, $accounts, $digests, str_repeat('A', 65));
                $tester->fail('Issuer longer than 64 characters must be rejected.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        } finally {
            $transaction->rollBack();
        }
    }
}

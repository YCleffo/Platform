<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\CreateTotpFactorCommand;
use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\ExternalIdentityRepository;
use App\Core\Auth\Application\AuthProviderRepository;
use App\Core\Auth\Application\RecoveryCodeRepository;
use App\Core\Auth\Application\RecoveryCodeService;
use App\Core\Auth\Application\TotpFactorRepository;
use App\Core\Auth\Application\TotpSecretCipher;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class ExternalIdentityMfaPersistenceCest
{
    public function lifecycleIsAtomicAndSecretSafe(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $accountId = (string) $db->createCommand(
                "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'mfa persistence') RETURNING id::text",
            )->queryScalar();
            $cipher = $container->get(TotpSecretCipher::class);
            $plaintext = random_bytes(20);
            $encrypted = $cipher->encrypt($plaintext);
            $factors = $container->get(TotpFactorRepository::class);
            $pending = $factors->createPending(new CreateTotpFactorCommand(
                accountId: $accountId,
                label: 'test',
                secretEncryptedHex: $encrypted->ciphertextHex,
                algorithm: 'sha1',
                digits: 6,
                periodSeconds: 30,
                secretKeyVersion: $encrypted->keyVersion,
                setupExpiresAt: (new DateTimeImmutable('+10 minutes'))->format('Y-m-d\TH:i:s\Z'),
            ));
            assertNull($pending->verifiedAt);
            assertFalse(str_contains($pending->secretEncryptedHex, bin2hex($plaintext)));
            assertNotNull($factors->activate($pending->id, $accountId, 100));
            assertFalse($factors->acceptCounter($pending->id, 100));
            assertTrue($factors->acceptCounter($pending->id, 101));

            $recovery = new RecoveryCodeService();
            $rawCodes = $recovery->generate(2);
            $codes = $container->get(RecoveryCodeRepository::class);
            $codes->store($accountId, array_map($recovery->hashHex(...), $rawCodes));
            assertSame(2, $codes->countAvailable($accountId));
            assertNotNull($codes->consumeCode($accountId, $recovery->hashHex($rawCodes[0])));
            assertNull($codes->consumeCode($accountId, $recovery->hashHex($rawCodes[0])));
            assertSame(1, $codes->countAvailable($accountId));

            $provider = $container->get(AuthProviderRepository::class)->findByCode('google');
            assertNotNull($provider);
            $identities = $container->get(ExternalIdentityRepository::class);
            $linked = $identities->link($accountId, $provider->id, new ExternalIdentityProfile(
                subject: 'test-' . bin2hex(random_bytes(12)),
                email: 'snapshot@example.test',
                emailVerified: true,
                displayName: 'Snapshot',
                avatarUrl: 'https://example.test/avatar',
                claims: ['locale' => 'en-US'],
            ));
            assertSame($accountId, $linked->accountId);
            assertCount(1, $identities->findByAccount($accountId));
            assertSame([], array_intersect(['access_token', 'refresh_token', 'id_token'], array_keys($linked->claims)));
        } finally {
            $transaction->rollBack();
        }
    }
}

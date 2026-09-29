<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\CredentialLifecycleRepository;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertNull;

final readonly class HumanCredentialBoundaryCest
{
    public function serviceAndSystemCannotUseHumanCredentialLifecycle(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $credentials = $container->get(CredentialLifecycleRepository::class);
        $transaction = $db->beginTransaction();
        try {
            foreach (['service', 'system'] as $type) {
                $person = (string) $db->createCommand(
                    "INSERT INTO core.persons (given_name, family_name) VALUES ('Credential', 'Fixture') RETURNING id::text",
                )->queryScalar();
                $account = (string) $db->createCommand(
                    "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), CAST(:type AS core.account_type), 'active') RETURNING id::text",
                    [':person' => $person, ':type' => $type],
                )->queryScalar();
                $email = 'legacy-' . $type . '-' . bin2hex(random_bytes(5)) . '@example.test';
                $db->createCommand(<<<'SQL'
                    INSERT INTO core.auth_identifiers (account_id, identifier_type, value, normalized_value,
                        is_primary, can_recover, verified_at)
                    VALUES (CAST(:account AS uuid), 'email', :email, :email, true, true, now())
                    SQL, [':account' => $account, ':email' => $email])->execute();
                foreach (['account_activation', 'password_reset'] as $challengeType) {
                    $db->createCommand(<<<'SQL'
                        INSERT INTO core.auth_challenges (account_id, challenge_type, secret_hash,
                            target_normalized, expires_at)
                        VALUES (CAST(:account AS uuid), :type, decode(:hash, 'hex'), :email,
                            now() + interval '1 hour')
                        SQL, [':account' => $account, ':type' => $challengeType,
                            ':hash' => hash('sha256', $type . $challengeType), ':email' => $email])->execute();
                }
                assertNull($credentials->findPrimaryEmail($account));
                assertNull($credentials->findRecoverableEmail($email));
                assertNull($credentials->ensurePrimaryEmail($account, $email, $email));
                assertNull($credentials->completeActivation(hash('sha256', $type . 'account_activation'), 'hash', 'argon2id'));
                assertNull($credentials->completePasswordReset(hash('sha256', $type . 'password_reset'), 'hash', 'argon2id'));
            }
        } finally {
            $transaction->rollBack();
        }
    }
}

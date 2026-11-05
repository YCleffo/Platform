<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Tests\Support\BrowserProviderTransport;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BrowserProviderTransportTest extends TestCase
{
    private string $nonce;

    protected function setUp(): void { $this->nonce = bin2hex(random_bytes(24)); }
    protected function tearDown(): void
    {
        $path = BrowserProviderTransport::stateFile($this->nonce);
        if (is_file($path)) unlink($path);
    }

    private function client(string $env = 'test', bool $isolated = true, string $database = 'platform_commercial_test'): HttpClientInterface
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn($database);
        return (new BrowserProviderTransport(new MockHttpClient(), $db, $env, $isolated, $this->nonce, true))->client();
    }

    public function testDisabledBoundaryKeepsTheRealClientWithoutDatabaseAccess(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchOne');
        $inner = new MockHttpClient();
        self::assertSame($inner, (new BrowserProviderTransport($inner, $db, 'prod', false, '', false))->client());
    }

    public function testBoundaryRefusesProductionNonisolatedAndWorkingDatabase(): void
    {
        foreach ([['prod', true, 'platform_commercial_test'], ['test', false, 'platform_commercial_test'], ['test', true, 'platform_commercial']] as [$env, $isolated, $db]) {
            try {
                $this->client($env, $isolated, $db);
                self::fail('Unsafe provider transport was enabled.');
            } catch (\LogicException) { self::addToAssertionCount(1); }
        }
    }

    public function testBoundaryRefusesMissingIsolationNonce(): void
    {
        $db = $this->createStub(Connection::class);
        $this->expectException(\LogicException::class);
        (new BrowserProviderTransport(new MockHttpClient(), $db, 'test', true, '', true))->client();
    }

    public function testProviderKeyIsStableAndResponsesRetainMoneyAndMetadata(): void
    {
        $client = $this->client();
        $options = ['headers' => ['Idempotence-Key' => 'stable-key'], 'json' => ['amount' => ['value' => '10.05', 'currency' => 'RUB'], 'metadata' => ['payment_id' => 'local-payment']]];
        $first = $client->request('POST', 'https://api.yookassa.ru/v3/payments', $options)->toArray();
        $second = $this->client()->request('POST', 'https://api.yookassa.ru/v3/payments', $options)->toArray();
        self::assertSame($first, $second);
        self::assertSame('10.05', $first['amount']['value']);
        self::assertSame(['payment_id' => 'local-payment'], $first['metadata']);
        self::assertSame('pending', $first['status']);
    }

    public function testUncertainCreateRetainsProviderStateForAuthoritativeLookup(): void
    {
        file_put_contents(BrowserProviderTransport::stateFile($this->nonce), json_encode(['next_payment' => 'uncertain'], JSON_THROW_ON_ERROR));
        $client = $this->client();
        $response = $client->request('POST', 'https://api.yookassa.ru/v3/payments', ['headers' => ['Idempotence-Key' => 'uncertain-key'], 'json' => ['amount' => ['value' => '1.00', 'currency' => 'RUB']]]);
        self::assertSame(503, $response->getStatusCode());
        $response->getContent(false);
        $id = 'fixture-' . substr(hash('sha256', 'payments/uncertain-key'), 0, 32);
        self::assertSame('pending', $this->client()->request('GET', 'https://api.yookassa.ru/v3/payments/' . $id)->toArray()['status']);
    }

    public function testUnexpectedHostCannotReachTheNetwork(): void
    {
        $this->expectException(\LogicException::class);
        $this->client()->request('GET', 'https://example.com/');
    }

    public function testOutageKeepsAcceptedPaymentAndIdempotencyAfterRecovery(): void
    {
        $file = BrowserProviderTransport::stateFile($this->nonce);
        file_put_contents($file, json_encode(['outage' => true], JSON_THROW_ON_ERROR));
        $options = ['headers' => ['Idempotence-Key' => 'outage-key'], 'json' => ['amount' => ['value' => '5.00', 'currency' => 'RUB']]];
        $id = 'fixture-' . substr(hash('sha256', 'payments/outage-key'), 0, 32);
        foreach (['POST', 'GET'] as $method) {
            $response = $this->client()->request($method, 'https://api.yookassa.ru/v3/payments' . ($method === 'GET' ? '/' . $id : ''), $method === 'POST' ? $options : []);
            self::assertSame(503, $response->getStatusCode());
            $response->getContent(false);
        }
        $state = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $state['payments']);
        $state['outage'] = false;
        file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));
        $created = $this->client()->request('POST', 'https://api.yookassa.ru/v3/payments', $options)->toArray();
        self::assertSame($id, $created['id']);
        self::assertSame('5.00', $created['amount']['value']);
        self::assertSame($created, $this->client()->request('GET', 'https://api.yookassa.ru/v3/payments/' . $id)->toArray());
    }

    public function testDeclinedPaymentDoesNotEchoTheRedirectRequestAsAConfirmation(): void
    {
        file_put_contents(BrowserProviderTransport::stateFile($this->nonce), json_encode(['next_payment' => 'declined'], JSON_THROW_ON_ERROR));
        $value = $this->client()->request('POST', 'https://api.yookassa.ru/v3/payments', [
            'headers' => ['Idempotence-Key' => 'declined-key'],
            'json' => ['confirmation' => ['type' => 'redirect', 'return_url' => 'https://example.test/return']],
        ])->toArray();
        self::assertSame('canceled', $value['status']);
        self::assertArrayNotHasKey('confirmation', $value);
        self::assertSame('general_decline', $value['cancellation_details']['reason']);
    }
}

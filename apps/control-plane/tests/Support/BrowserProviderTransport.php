<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Test-only provider wire boundary. The real gateways, HTTP actions and financial services run unchanged. */
final readonly class BrowserProviderTransport
{
    public function __construct(private HttpClientInterface $inner, private Connection $db,
        private string $environment, private bool $isolated, private string $nonce, private bool $enabled) {}

    public function client(): HttpClientInterface
    {
        if (!$this->enabled) return $this->inner;
        if ($this->environment !== 'test' || !$this->isolated || strlen($this->nonce) < 32
            || $this->db->fetchOne('SELECT current_database()') !== 'platform_commercial_test') {
            throw new \LogicException('Browser provider transport requires an isolated test database and nonce.');
        }
        return new MockHttpClient($this->request(...));
    }

    public static function stateFile(string $nonce): string
    {
        return sys_get_temp_dir() . '/platform-browser-provider-' . hash('sha256', $nonce) . '.json';
    }

    /** @param array<string,mixed> $options */
    private function request(string $method, string $url, array $options): MockResponse
    {
        if (parse_url($url, PHP_URL_HOST) !== 'api.yookassa.ru') {
            throw new \LogicException('Unexpected host at the isolated payment boundary.');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('~\A/v3/(payments|refunds|receipts)(?:/([a-zA-Z0-9_-]+))?\z~D', $path, $match) !== 1) {
            throw new \LogicException('Unexpected provider endpoint.');
        }
        $body = isset($options['body']) && is_string($options['body'])
            ? json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR) : [];
        if (!is_array($body)) throw new \LogicException('Expected a provider JSON object.');
        $key = '';
        foreach (($options['headers'] ?? []) as $header) {
            if (is_string($header) && stripos($header, 'Idempotence-Key:') === 0) $key = trim(substr($header, 16));
        }
        $file = fopen(self::stateFile($this->nonce), 'c+');
        if ($file === false || !flock($file, LOCK_EX)) throw new \RuntimeException('Provider fixture lock failed.');
        try {
            $raw = stream_get_contents($file);
            $state = $raw ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : [];
            if (!is_array($state)) throw new \LogicException('Invalid provider fixture state.');
            $collection = $match[1];
            $id = $match[2] ?? null;
            $status = 200;
            $state['calls'][] = ['method' => $method, 'path' => $path, 'key' => $key];
            if ($method === 'POST' && $id === null) {
                if ($key === '') throw new \LogicException('Provider idempotency key missing.');
                $id = 'fixture-' . substr(hash('sha256', $collection . '/' . $key), 0, 32);
                if (!isset($state[$collection][$id])) {
                    $mode = $collection === 'payments' ? ($state['next_payment'] ?? 'pending') : 'pending';
                    if ($collection === 'payments') $state['next_payment'] = 'pending';
                    $document = ['id' => $id] + $body;
                    if ($collection === 'payments') {
                        $document['status'] = $mode === 'declined' ? 'canceled' : ($mode === 'capture' ? 'waiting_for_capture' : 'pending');
                        $document['paid'] = false;
                        $document['metadata'] = $body['metadata'] ?? [];
                        if ($mode === 'declined') unset($document['confirmation']);
                        else $document['confirmation'] = ['type' => 'redirect', 'confirmation_url' => 'https://provider.e2e.test/confirm/' . $id];
                        if ($mode === 'declined') $document['cancellation_details'] = ['reason' => 'general_decline'];
                        if (($body['save_payment_method'] ?? false) === true) {
                            $document['payment_method'] = ['id' => 'fixture-saved-card-' . $id, 'type' => 'bank_card', 'saved' => true, 'card' => ['last4' => '4242', 'card_type' => 'Visa']];
                        }
                        if ($mode === 'uncertain') $status = 503;
                    } elseif ($collection === 'refunds') {
                        $document['status'] = 'succeeded';
                        $document['created_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    } else {
                        $document['status'] = 'succeeded';
                        $document['fiscal_document_attribute'] = ['registered_at' => gmdate('Y-m-d\TH:i:s\Z')];
                    }
                    $state[$collection][$id] = $document;
                }
            }
            $document = $state[$collection][$id ?? ''] ?? null;
            if (!is_array($document)) { $status = 404; $document = ['type' => 'error', 'code' => 'not_found']; }
            if (($state['outage'] ?? false) === true) $status = 503;
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, json_encode($state, JSON_THROW_ON_ERROR)) === false) throw new \RuntimeException('Provider fixture write failed.');
            fflush($file);
            return new MockResponse(json_encode($status === 503 ? ['type' => 'error', 'code' => 'temporary_unavailable'] : $document, JSON_THROW_ON_ERROR),
                ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}

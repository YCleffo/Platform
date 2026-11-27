<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure;

use App\Realtime\Application\RealtimeChannelAuthorizerInterface;
use App\Realtime\Application\RealtimeService;
use RuntimeException;
use Throwable;

/**
 * Pure PHP 8.3 RFC 6455 non-blocking WebSocket server backed by Redis Pub/Sub & PostgreSQL Outbox.
 */
final class WebSocketServer
{
    private const WS_MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    public const MAX_CLIENTS = 2000;
    public const MAX_HANDSHAKE_BYTES = 16384;
    public const MAX_FRAME_BYTES = 65536;
    public const MAX_READ_BUFFER_BYTES = 131072;
    public const MAX_WRITE_BUFFER_BYTES = 2097152;
    public const MAX_CHANNELS_PER_CLIENT = 200;
    public const MAX_SEEN_SEQ = 5000;
    public const AUTH_REVERIFY_INTERVAL = 10.0;
    private const RELAY_INTERVAL_ACTIVE = 0.03;
    private const RELAY_INTERVAL_IDLE = 0.05;

    /** @var array<int, array{socket: resource, handshaked: bool, buffer: string, writeBuffer: string, accountId: string, personId: string, sessionId: string, org: string, channels: array<string, true>, seenSeq: array<int, true>, lastPingAt: float, lastPongAt: float, lastAuthCheckAt: float}> */
    private array $clients = [];

    /** @var resource|null */
    private mixed $redisSubSocket = null;
    private string $redisBuffer = '';

    public function __construct(
        private readonly RealtimeService $realtime,
        private readonly RealtimeChannelAuthorizerInterface $authorizer,
        private readonly string $redisHost = 'redis',
        private readonly int $redisPort = 6379,
        private readonly int $redisDatabase = 0,
    ) {}

    public function serve(string $host = '0.0.0.0', int $port = 8084, callable $onStart = null): void
    {
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException("Cannot bind WebSocket server on {$host}:{$port}: {$errstr} ({$errno})");
        }
        stream_set_blocking($server, false);

        if ($onStart !== null) {
            $onStart($host, $port);
        }

        $this->connectRedisSubscriber();

        $lastRelayAt = microtime(true);
        $lastRelayCount = 0;
        $lastHeartbeatAt = microtime(true);
        $lastAuthSweepAt = microtime(true);

        while (true) {
            $read = [$server];
            $write = [];
            $except = null;

            if (is_resource($this->redisSubSocket)) {
                $read[] = $this->redisSubSocket;
            }
            foreach ($this->clients as $id => $client) {
                $read[] = $client['socket'];
                if ($client['writeBuffer'] !== '') {
                    $write[] = $client['socket'];
                }
            }

            $timeoutSec = 0;
            $timeoutUsec = 25000; // 25ms tick for low-latency WS relay & socket I/O
            $changed = @stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($changed !== false && $changed > 0) {
                if (in_array($server, $read, true)) {
                    $conn = @stream_socket_accept($server, 0);
                    if (is_resource($conn)) {
                        if (count($this->clients) >= self::MAX_CLIENTS) {
                            @fwrite($conn, "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\n\r\n");
                            @fclose($conn);
                        } else {
                            stream_set_blocking($conn, false);
                            $cid = (int) $conn;
                            $nowInit = microtime(true);
                            $this->clients[$cid] = [
                                'socket' => $conn,
                                'handshaked' => false,
                                'buffer' => '',
                                'writeBuffer' => '',
                                'accountId' => '',
                                'personId' => '',
                                'sessionId' => '',
                                'org' => '',
                                'channels' => [],
                                'seenSeq' => [],
                                'lastPingAt' => $nowInit,
                                'lastPongAt' => $nowInit,
                                'lastAuthCheckAt' => $nowInit,
                            ];
                        }
                    }
                }

                if (is_resource($this->redisSubSocket) && in_array($this->redisSubSocket, $read, true)) {
                    $this->handleRedisData();
                }

                foreach ($read as $sock) {
                    if ($sock === $server || $sock === $this->redisSubSocket) {
                        continue;
                    }
                    $cid = (int) $sock;
                    if (isset($this->clients[$cid])) {
                        $this->handleClientRead($cid);
                    }
                }

                foreach ($write as $sock) {
                    $cid = (int) $sock;
                    if (isset($this->clients[$cid])) {
                        $this->flushClientWrite($cid);
                    }
                }
            }

            $now = microtime(true);
            $relayInterval = $lastRelayCount > 0 ? self::RELAY_INTERVAL_ACTIVE : self::RELAY_INTERVAL_IDLE;
            if ($now - $lastRelayAt >= $relayInterval) {
                $lastRelayAt = $now;
                try {
                    $lastRelayCount = $this->realtime->relayPending(100);
                } catch (Throwable) {
                    $lastRelayCount = 0;
                }
                if (!is_resource($this->redisSubSocket)) {
                    $this->connectRedisSubscriber();
                }
            }

            if ($now - $lastAuthSweepAt >= self::AUTH_REVERIFY_INTERVAL) {
                $lastAuthSweepAt = $now;
                $this->reverifyConnectedClients($now);
            }

            if ($now - $lastHeartbeatAt >= 15.0) {
                $lastHeartbeatAt = $now;
                $this->runHeartbeats($now);
            }
        }
    }

    private function connectRedisSubscriber(): void
    {
        if (is_resource($this->redisSubSocket)) {
            $old = $this->redisSubSocket;
            $this->redisSubSocket = null;
            @fclose($old);
        }
        $sock = @stream_socket_client("tcp://{$this->redisHost}:{$this->redisPort}", $errno, $errstr, 2.0);
        if (!is_resource($sock)) {
            $this->redisSubSocket = null;
            return;
        }
        stream_set_blocking($sock, true);
        if ($this->redisDatabase > 0) {
            fwrite($sock, "*2\r\n\$6\r\nSELECT\r\n\$" . strlen((string) $this->redisDatabase) . "\r\n{$this->redisDatabase}\r\n");
            @fgets($sock);
        }
        $chan = RealtimeService::REDIS_CHANNEL;
        fwrite($sock, "*2\r\n\$9\r\nSUBSCRIBE\r\n\$" . strlen($chan) . "\r\n{$chan}\r\n");
        stream_set_blocking($sock, false);
        $this->redisSubSocket = $sock;
        $this->redisBuffer = '';
    }

    private function handleRedisData(): void
    {
        if (!is_resource($this->redisSubSocket)) {
            return;
        }
        $chunk = @fread($this->redisSubSocket, 65536);
        if ($chunk === '' || $chunk === false) {
            if (@feof($this->redisSubSocket)) {
                $old = $this->redisSubSocket;
                $this->redisSubSocket = null;
                @fclose($old);
            }
            return;
        }
        $this->redisBuffer .= $chunk;

        while (true) {
            $parsed = $this->consumeRespArray($this->redisBuffer);
            if ($parsed === null) {
                break;
            }
            [$items, $rest] = $parsed;
            $this->redisBuffer = $rest;
            if (count($items) >= 3 && $items[0] === 'message') {
                $json = $items[2];
                $event = json_decode($json, true);
                if (is_array($event)) {
                    /** @var array<string, mixed> $event */
                    $this->dispatchEventToClients($event);
                }
            }
        }
    }

    /** @return array{0: list<string>, 1: string}|null */
    private function consumeRespArray(string $buf): ?array
    {
        if ($buf === '') {
            return null;
        }
        $pos = strpos($buf, "\r\n");
        if ($pos === false) {
            return null;
        }
        $first = $buf[0];
        if ($first !== '*') {
            // Skip status/integer line
            return [['_skip'], substr($buf, $pos + 2)];
        }
        $count = (int) substr($buf, 1, $pos - 1);
        $cursor = $pos + 2;
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $nextEnd = strpos($buf, "\r\n", $cursor);
            if ($nextEnd === false) {
                return null;
            }
            $line = substr($buf, $cursor, $nextEnd - $cursor);
            $cursor = $nextEnd + 2;
            if ($line === '' || $line[0] === ':') {
                $items[] = ltrim($line, ':');
                continue;
            }
            if ($line[0] === '$') {
                $len = (int) substr($line, 1);
                if ($len < 0) {
                    $items[] = '';
                    continue;
                }
                if (strlen($buf) < $cursor + $len + 2) {
                    return null;
                }
                $items[] = substr($buf, $cursor, $len);
                $cursor += $len + 2;
            } else {
                $items[] = $line;
            }
        }
        return [$items, substr($buf, $cursor)];
    }

    private function handleClientRead(int $cid): void
    {
        if (!isset($this->clients[$cid])) {
            return;
        }
        $sock = $this->clients[$cid]['socket'];
        $chunk = @fread($sock, 65536);
        if ($chunk === '' || $chunk === false) {
            if (@feof($sock)) {
                $this->dropClient($cid);
            }
            return;
        }
        $this->clients[$cid]['buffer'] .= $chunk;

        if (!$this->clients[$cid]['handshaked']) {
            $headerEnd = strpos($this->clients[$cid]['buffer'], "\r\n\r\n");
            if ($headerEnd === false) {
                if (strlen($this->clients[$cid]['buffer']) > self::MAX_HANDSHAKE_BYTES) {
                    @fwrite($sock, "HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\n\r\n");
                    $this->dropClient($cid);
                }
                return;
            }
            if ($headerEnd > self::MAX_HANDSHAKE_BYTES) {
                @fwrite($sock, "HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\n\r\n");
                $this->dropClient($cid);
                return;
            }
            $rawHeaders = substr($this->clients[$cid]['buffer'], 0, $headerEnd);
            $this->clients[$cid]['buffer'] = substr($this->clients[$cid]['buffer'], $headerEnd + 4);
            $this->performHandshake($cid, $rawHeaders);
            if (!isset($this->clients[$cid]) || $this->clients[$cid]['buffer'] === '') {
                return;
            }
        }

        if (strlen($this->clients[$cid]['buffer']) > self::MAX_READ_BUFFER_BYTES) {
            $this->closeClientWithCode($cid, 1009, 'read_buffer_overflow');
            return;
        }

        while (isset($this->clients[$cid])) {
            $frame = $this->decodeFrame($this->clients[$cid]['buffer']);
            if ($frame === null) {
                break;
            }
            if (!isset($frame['opcode'], $frame['payload'], $frame['remaining'])) {
                $this->closeClientWithCode($cid, 1009, 'frame_too_large');
                return;
            }
            $opcode = $frame['opcode'];
            $payload = $frame['payload'];
            $this->clients[$cid]['buffer'] = $frame['remaining'];

            if ($opcode === 0x8) { // Close
                $this->sendFrame($cid, '', 0x8);
                $this->flushClientWrite($cid);
                $this->dropClient($cid);
                return;
            }
            if ($opcode === 0x9) { // Ping -> Pong
                $this->sendFrame($cid, $payload, 0xA);
                $this->flushClientWrite($cid);
                continue;
            }
            if ($opcode === 0xA) { // Pong
                $this->clients[$cid]['lastPongAt'] = microtime(true);
                continue;
            }
            if ($opcode === 0x1 || $opcode === 0x2) { // Text/Binary
                $this->handleJsonCommand($cid, $payload);
            }
        }
    }

    private function performHandshake(int $cid, string $rawHeaders): void
    {
        $lines = explode("\r\n", $rawHeaders);
        $requestLine = $lines[0] ?? '';
        if (!preg_match('/^GET\s+([^\s]+)\s+HTTP\/1\.1$/i', $requestLine, $m)) {
            $this->dropClient($cid);
            return;
        }
        $parsedQuery = parse_url($m[1], PHP_URL_QUERY);
        $rawQuery = is_string($parsedQuery) ? $parsedQuery : '';
        parse_str($rawQuery, $query);
        $rawTicket = $query['ticket'] ?? null;
        $ticket = is_string($rawTicket) ? $rawTicket : '';

        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $k = strtolower(trim(substr($line, 0, $colon)));
                $v = trim(substr($line, $colon + 1));
                $headers[$k] = $v;
            }
        }

        $wsKey = $headers['sec-websocket-key'] ?? '';
        if ($wsKey === '') {
            @fwrite($this->clients[$cid]['socket'], "HTTP/1.1 400 Bad Request\r\n\r\n");
            $this->dropClient($cid);
            return;
        }

        $verified = $this->realtime->verifyWsTicket($ticket);
        if ($verified === null) {
            @fwrite($this->clients[$cid]['socket'], "HTTP/1.1 401 Unauthorized\r\nContent-Type: text/plain\r\n\r\ninvalid_ticket");
            $this->dropClient($cid);
            return;
        }

        $accept = base64_encode(sha1($wsKey . self::WS_MAGIC_GUID, true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

        $now = microtime(true);
        $this->clients[$cid]['handshaked'] = true;
        $this->clients[$cid]['accountId'] = $verified['accountId'];
        $this->clients[$cid]['personId'] = $verified['personId'];
        $this->clients[$cid]['sessionId'] = $verified['sessionId'];
        $this->clients[$cid]['org'] = $verified['org'];
        $this->clients[$cid]['lastAuthCheckAt'] = $now;
        $this->clients[$cid]['writeBuffer'] .= $response;

        // Automatically subscribe client to their own personal & org channels
        $userChan = 'user:' . $verified['personId'];
        $orgChan = 'org:' . $verified['org'];
        $this->clients[$cid]['channels'][$userChan] = true;
        $this->clients[$cid]['channels'][$orgChan] = true;

        // Mark online presence
        $this->realtime->setPresence($verified['org'], $verified['personId'], true);

        $this->sendJson($cid, [
            'type' => 'hello',
            'personId' => $verified['personId'],
            'organizationId' => $verified['org'],
            'channels' => [$userChan, $orgChan],
            'latestSeq' => $this->realtime->latestSeq($verified['org']),
            'serverTime' => gmdate(DATE_ATOM),
        ]);
        $this->flushClientWrite($cid);
    }

    private function handleJsonCommand(int $cid, string $payload): void
    {
        if (!isset($this->clients[$cid])) {
            return;
        }
        $msg = json_decode($payload, true);
        if (!is_array($msg)) {
            return;
        }
        $type = (string) ($msg['type'] ?? '');
        $client = $this->clients[$cid];

        if ($type === 'ping') {
            $this->clients[$cid]['lastPongAt'] = microtime(true);
            $this->realtime->setPresence($client['org'], $client['personId'], true);
            $this->sendJson($cid, ['type' => 'pong', 'ts' => gmdate(DATE_ATOM)]);
            $this->flushClientWrite($cid);
            return;
        }

        if ($type === 'subscribe') {
            $channel = trim((string) ($msg['channel'] ?? ''));
            $sinceSeq = isset($msg['sinceSeq']) && is_numeric($msg['sinceSeq']) ? (int) $msg['sinceSeq'] : null;
            if (!isset($this->clients[$cid]['channels'][$channel]) && count($this->clients[$cid]['channels']) >= self::MAX_CHANNELS_PER_CLIENT) {
                $this->sendJson($cid, ['type' => 'error', 'code' => 'too_many_channels', 'channel' => $channel]);
                $this->flushClientWrite($cid);
                return;
            }
            if (!$this->authorizer->canSubscribe($client['accountId'], $client['personId'], $client['org'], $channel)) {
                $this->sendJson($cid, ['type' => 'error', 'code' => 'channel_forbidden', 'channel' => $channel]);
                $this->flushClientWrite($cid);
                return;
            }
            $this->clients[$cid]['channels'][$channel] = true;
            $this->sendJson($cid, ['type' => 'subscribed', 'channel' => $channel]);
            if ($sinceSeq !== null) {
                $missed = $this->realtime->sinceSeq(
                    $client['accountId'],
                    $client['personId'],
                    $client['org'],
                    $channel,
                    $sinceSeq,
                );
                foreach ($missed as $ev) {
                    $seq = (int) ($ev['seq'] ?? 0);
                    if ($seq > 0 && !$this->recordSeenSeq($cid, $seq)) {
                        continue;
                    }
                    $this->sendJson($cid, ['type' => 'event', 'event' => $ev]);
                }
            }
            $this->flushClientWrite($cid);
            return;
        }

        if ($type === 'unsubscribe') {
            $channel = trim((string) ($msg['channel'] ?? ''));
            unset($this->clients[$cid]['channels'][$channel]);
            $this->sendJson($cid, ['type' => 'unsubscribed', 'channel' => $channel]);
            $this->flushClientWrite($cid);
            return;
        }

        if ($type === 'typing') {
            $roomId = (string) ($msg['roomId'] ?? '');
            $typing = (bool) ($msg['typing'] ?? true);
            try {
                $this->realtime->setTyping($client['accountId'], $client['personId'], $client['org'], $roomId, $typing);
            } catch (Throwable) {
                // Ignore invalid typing payload
            }
        }
    }

    /** @param array<string, mixed> $event */
    private function dispatchEventToClients(array $event): void
    {
        $org = (string) ($event['organizationId'] ?? '');
        $channel = (string) ($event['channel'] ?? '');
        $eventType = (string) ($event['eventType'] ?? '');
        $seq = (int) ($event['seq'] ?? 0);
        $rawTargets = $event['targetPersonIds'] ?? null;
        /** @var list<string>|null $targets */
        $targets = is_array($rawTargets)
            ? array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $rawTargets))
            : null;
        $isEphemeral = (bool) ($event['ephemeral'] ?? false);
        $actorPerson = isset($event['actorPersonId']) ? (string) $event['actorPersonId'] : null;
        /** @var array<string, mixed> $payload */
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        // Immediate revocation enforcement before delivering any subsequent events
        if ($eventType === 'auth.session.revoked') {
            $revokedSessionId = (string) ($payload['sessionId'] ?? $event['aggregateId'] ?? '');
            $revokedAccountId = (string) ($payload['accountId'] ?? $event['actorAccountId'] ?? '');
            foreach (array_keys($this->clients) as $cid) {
                if (!isset($this->clients[$cid]) || !$this->clients[$cid]['handshaked']) {
                    continue;
                }
                $c = $this->clients[$cid];
                $matchesSession = $revokedSessionId !== '' && $c['sessionId'] === $revokedSessionId;
                $matchesAccount = $revokedSessionId === '' && $revokedAccountId !== '' && $c['accountId'] === $revokedAccountId;
                if ($matchesSession || $matchesAccount) {
                    $this->closeClientWithCode($cid, 4001, 'session_revoked', 'session_revoked');
                }
            }
            return;
        }

        if ($eventType === 'org.membership.revoked') {
            $revokedPersonId = (string) ($payload['personId'] ?? $event['aggregateId'] ?? '');
            if ($revokedPersonId !== '') {
                foreach (array_keys($this->clients) as $cid) {
                    if (!isset($this->clients[$cid]) || !$this->clients[$cid]['handshaked']) {
                        continue;
                    }
                    $c = $this->clients[$cid];
                    if ($c['org'] === $org && $c['personId'] === $revokedPersonId) {
                        $this->closeClientWithCode($cid, 4001, 'membership_revoked', 'membership_revoked');
                    }
                }
            }
            return;
        }

        if ($eventType === 'chat.member.removed') {
            $removedRoomId = (string) ($payload['roomId'] ?? $event['aggregateId'] ?? '');
            $removedPersonId = (string) ($payload['personId'] ?? '');
            if ($removedRoomId !== '' && $removedPersonId !== '') {
                $roomChan = 'room:' . $removedRoomId;
                foreach (array_keys($this->clients) as $cid) {
                    if (!isset($this->clients[$cid]) || !$this->clients[$cid]['handshaked']) {
                        continue;
                    }
                    if ($this->clients[$cid]['org'] === $org && $this->clients[$cid]['personId'] === $removedPersonId && isset($this->clients[$cid]['channels'][$roomChan])) {
                        unset($this->clients[$cid]['channels'][$roomChan]);
                        $this->sendJson($cid, ['type' => 'unsubscribed', 'channel' => $roomChan, 'reason' => 'member_removed']);
                        $this->flushClientWrite($cid);
                    }
                }
            }
        }

        foreach (array_keys($this->clients) as $cid) {
            if (!isset($this->clients[$cid])) {
                continue;
            }
            $client = $this->clients[$cid];
            if (!$client['handshaked'] || $client['org'] !== $org) {
                continue;
            }
            if (!isset($client['channels'][$channel])) {
                continue;
            }
            if ($targets !== null && !in_array($client['personId'], $targets, true)) {
                continue;
            }
            if ($isEphemeral && $actorPerson !== null && $eventType === 'chat.typing' && $actorPerson === $client['personId']) {
                continue;
            }
            if ($seq > 0 && !$this->recordSeenSeq($cid, $seq)) {
                continue;
            }
            $this->sendJson($cid, ['type' => 'event', 'event' => $event]);
            $this->flushClientWrite($cid);
        }
    }

    private function reverifyConnectedClients(float $now): void
    {
        foreach (array_keys($this->clients) as $cid) {
            if (!isset($this->clients[$cid]) || !$this->clients[$cid]['handshaked']) {
                continue;
            }
            $client = $this->clients[$cid];
            if ($now - $client['lastAuthCheckAt'] < self::AUTH_REVERIFY_INTERVAL) {
                continue;
            }
            $this->clients[$cid]['lastAuthCheckAt'] = $now;

            try {
                if (!$this->realtime->isConnectionStillAuthorized($client['accountId'], $client['personId'], $client['sessionId'], $client['org'])) {
                    $this->closeClientWithCode($cid, 4001, 'session_revoked', 'session_revoked');
                    continue;
                }

                foreach (array_keys($client['channels']) as $chan) {
                    if (!str_starts_with($chan, 'room:')) {
                        continue;
                    }
                    if (!$this->authorizer->canSubscribe($client['accountId'], $client['personId'], $client['org'], $chan)) {
                        unset($this->clients[$cid]['channels'][$chan]);
                        $this->sendJson($cid, ['type' => 'unsubscribed', 'channel' => $chan, 'reason' => 'access_revoked']);
                        $this->flushClientWrite($cid);
                    }
                }
            } catch (Throwable) {
                // Transient DB hiccup: do not mass-disconnect healthy clients; retry on next sweep
            }
        }
    }

    private function recordSeenSeq(int $cid, int $seq): bool
    {
        if (!isset($this->clients[$cid])) {
            return false;
        }
        if (isset($this->clients[$cid]['seenSeq'][$seq])) {
            return false;
        }
        $this->clients[$cid]['seenSeq'][$seq] = true;
        if (count($this->clients[$cid]['seenSeq']) > self::MAX_SEEN_SEQ) {
            $this->clients[$cid]['seenSeq'] = array_slice($this->clients[$cid]['seenSeq'], -1000, null, true);
        }
        return true;
    }

    private function closeClientWithCode(int $cid, int $code, string $reason, ?string $errorCode = null): void
    {
        if (!isset($this->clients[$cid])) {
            return;
        }
        if ($errorCode !== null) {
            $this->sendJson($cid, ['type' => 'error', 'code' => $errorCode]);
        }
        if (isset($this->clients[$cid])) {
            $this->sendFrame($cid, pack('n', $code) . $reason, 0x8);
            $this->flushClientWrite($cid);
            $this->dropClient($cid);
        }
    }

    private function runHeartbeats(float $now): void
    {
        foreach (array_keys($this->clients) as $cid) {
            if (!isset($this->clients[$cid])) {
                continue;
            }
            $client = $this->clients[$cid];
            if (!$client['handshaked']) {
                if ($now - $client['lastPingAt'] > 10.0) {
                    $this->dropClient($cid);
                }
                continue;
            }
            if ($now - $client['lastPongAt'] > 75.0) {
                $this->dropClient($cid);
                continue;
            }
            $this->clients[$cid]['lastPingAt'] = $now;
            $this->sendFrame($cid, 'hb', 0x9);
            $this->flushClientWrite($cid);
        }
    }

    /** @param array<string, mixed> $data */
    private function sendJson(int $cid, array $data): void
    {
        if (!isset($this->clients[$cid])) {
            return;
        }
        $this->sendFrame($cid, json_encode($data, JSON_THROW_ON_ERROR), 0x1);
    }

    private function sendFrame(int $cid, string $payload, int $opcode): void
    {
        if (!isset($this->clients[$cid])) {
            return;
        }
        $len = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));
        if ($len <= 125) {
            $header .= chr($len);
        } elseif ($len <= 65535) {
            $header .= chr(126) . pack('n', $len);
        } else {
            $header .= chr(127) . pack('J', $len);
        }
        $frameBytes = $header . $payload;
        if (strlen($this->clients[$cid]['writeBuffer']) + strlen($frameBytes) > self::MAX_WRITE_BUFFER_BYTES) {
            $this->dropClient($cid);
            return;
        }
        $this->clients[$cid]['writeBuffer'] .= $frameBytes;
    }

    private function flushClientWrite(int $cid): void
    {
        if (!isset($this->clients[$cid]) || $this->clients[$cid]['writeBuffer'] === '') {
            return;
        }
        $sock = $this->clients[$cid]['socket'];
        $written = @fwrite($sock, $this->clients[$cid]['writeBuffer']);
        if ($written === false) {
            $this->dropClient($cid);
            return;
        }
        if ($written > 0) {
            $this->clients[$cid]['writeBuffer'] = substr($this->clients[$cid]['writeBuffer'], $written);
        }
    }

    /** @return array{opcode: int, payload: string, remaining: string}|array{tooLarge: true}|null */
    private function decodeFrame(string $buf): ?array
    {
        $len = strlen($buf);
        if ($len < 2) {
            return null;
        }
        $b1 = ord($buf[0]);
        $b2 = ord($buf[1]);
        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $payloadLen = $b2 & 0x7F;
        $offset = 2;

        if ($payloadLen === 126) {
            if ($len < 4) {
                return null;
            }
            $unpacked = unpack('nlen', substr($buf, 2, 2));
            $payloadLen = (int) ($unpacked['len'] ?? 0);
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($len < 10) {
                return null;
            }
            $unpacked = unpack('Jlen', substr($buf, 2, 8));
            $payloadLen = (int) ($unpacked['len'] ?? 0);
            $offset = 10;
        }

        if ($payloadLen < 0 || $payloadLen > self::MAX_FRAME_BYTES) {
            return ['tooLarge' => true];
        }

        $maskLen = $masked ? 4 : 0;
        if ($len < $offset + $maskLen + $payloadLen) {
            return null;
        }

        $mask = $masked ? substr($buf, $offset, 4) : '';
        $offset += $maskLen;
        $data = substr($buf, $offset, $payloadLen);
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= $data[$i] ^ $mask[$i % 4];
            }
            $data = $unmasked;
        }

        return ['opcode' => $opcode, 'payload' => $data, 'remaining' => substr($buf, $offset + $payloadLen)];
    }

    private function dropClient(int $cid): void
    {
        if (!isset($this->clients[$cid])) {
            return;
        }
        $client = $this->clients[$cid];
        unset($this->clients[$cid]);
        @fclose($client['socket']);

        if ($client['handshaked'] && $client['org'] !== '' && $client['personId'] !== '') {
            $stillOnline = false;
            foreach ($this->clients as $other) {
                if ($other['handshaked'] && $other['org'] === $client['org'] && $other['personId'] === $client['personId']) {
                    $stillOnline = true;
                    break;
                }
            }
            if (!$stillOnline) {
                $this->realtime->setPresence($client['org'], $client['personId'], false);
            }
        }
    }
}

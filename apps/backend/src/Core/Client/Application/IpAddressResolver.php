<?php

declare(strict_types=1);

namespace App\Core\Client\Application;

use Psr\Http\Message\ServerRequestInterface;

final readonly class IpAddressResolver
{
    public function __construct(private TrustedProxies $trustedProxies) {}

    public function resolve(ServerRequestInterface $request): ?string
    {
        $remote = $this->normalize($request->getServerParams()['REMOTE_ADDR'] ?? null);
        if ($remote === null || !$this->trustedProxies->contains($remote)) {
            return $remote;
        }

        $forwarded = $request->getHeaderLine('Forwarded');
        if ($forwarded !== '') {
            $chain = $this->parseForwarded($forwarded);
            return $chain === null ? $remote : $this->clientFromChain($chain, $remote);
        }

        $xForwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if ($xForwardedFor !== '') {
            $chain = $this->parseXForwardedFor($xForwardedFor);
            return $chain === null ? $remote : $this->clientFromChain($chain, $remote);
        }

        return $remote;
    }

    /** @return list<string>|null */
    private function parseForwarded(string $header): ?array
    {
        $addresses = [];
        foreach (explode(',', $header) as $element) {
            $found = false;
            foreach (explode(';', $element) as $pair) {
                [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
                if (strtolower((string) $name) !== 'for' || $value === null) {
                    continue;
                }
                $found = true;
                $address = $this->normalizeForwardedToken($value);
                if ($address === null) {
                    return null;
                }
                $addresses[] = $address;
                break;
            }
            if (!$found) {
                return null;
            }
        }

        return $addresses;
    }

    /** @return list<string>|null */
    private function parseXForwardedFor(string $header): ?array
    {
        $addresses = [];
        foreach (explode(',', $header) as $value) {
            $address = $this->normalize(trim($value));
            if ($address === null) {
                return null;
            }
            $addresses[] = $address;
        }

        return $addresses;
    }

    /** @param list<string> $forwarded */
    private function clientFromChain(array $forwarded, string $remote): string
    {
        $chain = [...$forwarded, $remote];
        for ($index = count($chain) - 1; $index > 0; --$index) {
            if (!$this->trustedProxies->contains($chain[$index])) {
                return $chain[$index];
            }
        }

        return $chain[0];
    }

    private function normalizeForwardedToken(string $token): ?string
    {
        $value = trim($token, " \t\n\r\0\x0B\"");
        if (preg_match('/\A\[([^]]+)](?::\d+)?\z/', $value, $match) === 1) {
            return $this->normalize($match[1]);
        }
        if (preg_match('/\A([^:]+):\d+\z/', $value, $match) === 1) {
            return $this->normalize($match[1]);
        }

        return $this->normalize($value);
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($value);
        if ($packed === false) {
            return null;
        }

        $normalized = inet_ntop($packed);
        return $normalized === false ? null : $normalized;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Client;

use App\Core\Client\Application\IpAddressResolver;
use App\Core\Client\Application\TrustedProxies;
use Codeception\Test\Unit;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;

final class IpAddressResolverTest extends Unit
{
    public function testRejectsSpoofedForwardedHeaderFromUntrustedPeer(): void
    {
        $request = new ServerRequest(
            serverParams: ['REMOTE_ADDR' => '203.0.113.10'],
            headers: ['X-Forwarded-For' => '198.51.100.20'],
        );

        assertSame(
            '203.0.113.10',
            (new IpAddressResolver(new TrustedProxies(['10.0.0.0/8'])))->resolve($request),
        );
    }

    public function testWalksTrustedProxyChainFromRightToLeft(): void
    {
        $request = new ServerRequest(
            serverParams: ['REMOTE_ADDR' => '10.0.0.2'],
            headers: ['X-Forwarded-For' => '198.51.100.20, 10.0.0.3'],
        );

        assertSame(
            '198.51.100.20',
            (new IpAddressResolver(new TrustedProxies(['10.0.0.0/8'])))->resolve($request),
        );
    }

    public function testSupportsForwardedIpv6AndCidr(): void
    {
        $request = new ServerRequest(
            serverParams: ['REMOTE_ADDR' => '2001:db8:ffff::2'],
            headers: ['Forwarded' => 'for="[2001:db8::1234]";proto=https'],
        );

        assertSame(
            '2001:db8::1234',
            (new IpAddressResolver(new TrustedProxies(['2001:db8:ffff::/48'])))->resolve($request),
        );
    }
}

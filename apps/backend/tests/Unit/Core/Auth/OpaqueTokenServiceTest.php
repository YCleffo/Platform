<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Auth;

use App\Core\Auth\Application\OpaqueTokenService;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class OpaqueTokenServiceTest extends Unit
{
    public function testIssuesOpaqueTokensWithAtLeast256BitsOfEntropy(): void
    {
        $service = new OpaqueTokenService();
        $first = $service->issue();
        $second = $service->issue();

        assertSame(43, strlen($first->raw));
        assertSame(64, strlen($first->hashHex));
        assertTrue($service->hasValidFormat($first->raw));
        assertSame(hash('sha256', $first->raw), $first->hashHex);
        assertNotSame($first->raw, $second->raw);
        assertNotSame($first->hashHex, $second->hashHex);
    }

    public function testRejectsMalformedTokenFormats(): void
    {
        $service = new OpaqueTokenService();

        assertSame(false, $service->hasValidFormat('short'));
        assertSame(false, $service->hasValidFormat(str_repeat('a', 42) . '!'));
    }
}

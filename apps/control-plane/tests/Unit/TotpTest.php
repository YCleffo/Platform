<?php
declare(strict_types=1);
namespace ControlPlane\Tests\Unit;
use ControlPlane\Identity\Security\Totp;
use PHPUnit\Framework\TestCase;
final class TotpTest extends TestCase
{
    public function testAcceptsRfc6238Sha1VectorInSixDigitMode(): void
    {
        self::assertSame(1, Totp::counterFor('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59));
        self::assertNull(Totp::counterFor('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287083', 59));
    }
}

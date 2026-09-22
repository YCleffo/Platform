<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Shared;

use App\Api\Shared\OffsetPagination;
use Codeception\Test\Unit;
use Yiisoft\Input\Http\InputValidationException;

use function PHPUnit\Framework\assertSame;

final class OffsetPaginationTest extends Unit
{
    public function testDefaultsAndExplicitValues(): void
    {
        $defaults = OffsetPagination::fromQuery([]);
        assertSame(50, $defaults->limit);
        assertSame(0, $defaults->offset);

        $explicit = OffsetPagination::fromQuery(['limit' => '100', 'offset' => '20']);
        assertSame(100, $explicit->limit);
        assertSame(20, $explicit->offset);
    }

    public function testRejectsUnsafeBounds(): void
    {
        $this->expectException(InputValidationException::class);
        OffsetPagination::fromQuery(['limit' => '101', 'offset' => '-1']);
    }
}

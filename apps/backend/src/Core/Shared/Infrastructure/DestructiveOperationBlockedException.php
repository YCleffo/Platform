<?php

declare(strict_types=1);

namespace App\Core\Shared\Infrastructure;

use RuntimeException;

final class DestructiveOperationBlockedException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace App\Core\Security\Application;

use RuntimeException;

final class RateLimiterUnavailableException extends RuntimeException {}

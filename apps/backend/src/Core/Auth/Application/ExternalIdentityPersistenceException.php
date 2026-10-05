<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use RuntimeException;

/** Infrastructure persistence failure that is not an identity-ownership conflict. */
final class ExternalIdentityPersistenceException extends RuntimeException {}

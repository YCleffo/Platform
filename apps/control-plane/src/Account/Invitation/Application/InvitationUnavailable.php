<?php

declare(strict_types=1);

namespace ControlPlane\Account\Invitation\Application;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class InvitationUnavailable extends AccessDeniedException
{
    public function __construct()
    {
        parent::__construct('Invitation is not available.');
    }
}

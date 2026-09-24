<?php

declare(strict_types=1);

namespace ControlPlane;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        if ($this->getEnvironment() === 'prod' && strlen((string) getenv('CONTROL_APP_SECRET')) < 32) {
            throw new \RuntimeException('Control Plane production secret is not configured.');
        }

        parent::boot();
    }
}

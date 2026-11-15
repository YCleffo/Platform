<?php

declare(strict_types=1);

namespace App\Branding;

use App\Api\Shared\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

final readonly class BrandingReadAction
{
    public function __invoke(BrandingSettings $branding, ResponseFactory $responses): ResponseInterface
    {
        return $responses->success($branding->read())->withHeader('Cache-Control', 'no-store');
    }
}

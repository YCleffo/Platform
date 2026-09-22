<?php

declare(strict_types=1);

namespace App\Core\Communication\Application;

use App\Core\Communication\Domain\EmailTemplate;

interface TemplateRendererInterface
{
    /** @param array<string, scalar|null> $variables */
    public function render(
        string $templateCode,
        string $locale,
        array $variables,
    ): EmailTemplate;
}

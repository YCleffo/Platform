<?php

declare(strict_types=1);

namespace App\Core\Presence\Application;

use App\Core\AccountSettings\Application\AccountSettingsService;

/** Common privacy gate. Modules must additionally enforce their own relationships. */
final readonly class PresenceVisibilityPolicy
{
    public function __construct(private AccountSettingsService $settings, private PresenceService $presence) {}

    /** @return 'hidden'|'online'|'offline'|'unknown' */
    public function visibleState(string $subjectAccountId): string
    {
        $privacy = $this->settings->get($subjectAccountId)->settings['privacy'] ?? null;
        if (!is_array($privacy) || ($privacy['show_online_status'] ?? null) !== true) {
            return 'hidden';
        }
        return $this->presence->state($subjectAccountId);
    }
}

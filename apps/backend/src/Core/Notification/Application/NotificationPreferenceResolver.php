<?php

declare(strict_types=1);

namespace App\Core\Notification\Application;

use App\Core\AccountSettings\Application\AccountSettingsService;
use InvalidArgumentException;

final readonly class NotificationPreferenceResolver
{
    public function __construct(private AccountSettingsService $settings) {}

    public function allows(string $accountId, string $notificationClass, string $channel): bool
    {
        if (!in_array($notificationClass, ['security', 'transactional', 'product'], true)
            || !in_array($channel, ['email', 'browser', 'sound'], true)) {
            throw new InvalidArgumentException('Unknown notification class or channel.');
        }
        if ($channel === 'email' && $notificationClass !== 'product') {
            return true;
        }
        $settings = $this->settings->get($accountId)->settings;
        $notifications = $settings['notifications'] ?? null;
        return is_array($notifications) && ($notifications[$channel] ?? null) === true;
    }
}

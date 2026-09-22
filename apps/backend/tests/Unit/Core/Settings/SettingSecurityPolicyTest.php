<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Settings;

use App\Core\Settings\Application\SettingSecurityPolicy;
use PHPUnit\Framework\TestCase;

final class SettingSecurityPolicyTest extends TestCase
{
    public function testRejectsSecretLikeSettingKeys(): void
    {
        $policy = new SettingSecurityPolicy();
        foreach (
            ['smtp_password', 'databasePassword', 'API_KEY', 'private-key', 'serviceCredential', 'accessToken'] as $key
        ) {
            self::assertFalse($policy->isSafe($key, 'redacted'), $key);
        }
    }

    public function testRejectsSecretLikeNestedKeys(): void
    {
        self::assertFalse((new SettingSecurityPolicy())->isSafe('mail.options', [
            'transport' => ['smtpPassword' => 'redacted'],
        ]));
    }

    public function testAllowsOrdinaryConfiguration(): void
    {
        self::assertTrue((new SettingSecurityPolicy())->isSafe('ui.theme', [
            'accentColor' => '#123456',
            'compact' => true,
        ]));
    }
}

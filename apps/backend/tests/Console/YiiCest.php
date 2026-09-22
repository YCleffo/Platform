<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Tests\Support\ConsoleTester;

final readonly class YiiCest
{
    public function base(ConsoleTester $I): void
    {
        $I->runApp();
        $I->canSeeResultCodeIs(0);
        $I->seeInShellOutput('Yii Console');
    }

    public function authCleanupRunsSuccessfully(ConsoleTester $I): void
    {
        $I->runApp('auth/cleanup');
        $I->canSeeResultCodeIs(0);
        $I->seeInShellOutput('Expired challenges removed:');
        $I->seeInShellOutput('Consumed challenges removed:');
        $I->seeInShellOutput('Cancelled challenges removed:');
        $I->seeInShellOutput('Revoked/used recovery codes removed:');
        $I->seeInShellOutput('Expired TOTP setups removed:');
        $I->seeInShellOutput('Total removed:');
    }
}

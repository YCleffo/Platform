<?php

declare(strict_types=1);

namespace App\Tests\Support\Extension;

use App\Environment;
use App\Tests\Support\ApiTester;
use App\Tests\Support\TestReset;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Extension;
use Codeception\Module\PhpBrowser;
use Codeception\Module\REST;

final class TestIsolationExtension extends Extension
{
    public static array $events = [
        Events::SUITE_BEFORE => 'beforeSuite',
        Events::TEST_AFTER => 'afterTest',
        Events::SUITE_AFTER => 'afterSuite',
    ];

    public function beforeSuite(SuiteEvent $e): void
    {
        if (Environment::isTest()) {
            TestReset::reset(ApiTester::getDb(), ApiTester::getRedis());
        }
    }

    public function afterTest(TestEvent $e): void
    {
        $this->resetBrowserClient();
    }

    public function afterSuite(SuiteEvent $e): void
    {
        if (Environment::isTest()) {
            TestReset::reset(ApiTester::getDb(), ApiTester::getRedis());
        }
    }

    private function resetBrowserClient(): void
    {
        if ($this->hasModule('REST')) {
            /** @var REST $rest */
            $rest = $this->getModule('REST');
            $rest->deleteHeader('Authorization');
            $rest->deleteHeader('X-Client-Locale');
            $rest->deleteHeader('Accept-Language');
            $rest->deleteHeader('Content-Type');
            $rest->deleteHeader('Origin');
            $rest->deleteHeader('X-Forwarded-For');
            $rest->deleteHeader('X-Real-IP');
        }

        if ($this->hasModule('PhpBrowser')) {
            /** @var PhpBrowser $browser */
            $browser = $this->getModule('PhpBrowser');
            $browser->deleteHeader('Authorization');
            $browser->deleteHeader('X-Client-Locale');
            $browser->deleteHeader('Accept-Language');
            $browser->deleteHeader('Content-Type');
            $browser->deleteHeader('Origin');
            $browser->deleteHeader('X-Forwarded-For');
            $browser->deleteHeader('X-Real-IP');
            if ($browser->client !== null) {
                try {
                    $browser->client->getCookieJar()->clear();
                } catch (\Throwable) {
                }
            }
        }
    }
}

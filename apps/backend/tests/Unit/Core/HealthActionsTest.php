<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Api\LiveHealthAction;
use App\Api\ReadyHealthAction;
use App\PlatformRuntime\Scheduler\OperationalStatus;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final class HealthActionsTest extends TestCase
{
    public function testLiveHasNoDependencyAndReadyResponseHidesFailure(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withStatus')->with(503)->willReturnSelf();
        $factory = $this->createMock(DataResponseFactoryInterface::class);
        $payloads = [];
        $factory->method('createResponse')->willReturnCallback(
            static function (mixed $data) use (&$payloads, $response): ResponseInterface {
                $payloads[] = $data;
                return $response;
            },
        );
        self::assertSame($response, (new LiveHealthAction())($factory));
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willThrowException(new \RuntimeException('private DB path'));
        $redis = $this->createMock(ClientInterface::class);
        self::assertSame($response, (new ReadyHealthAction())($factory, new OperationalStatus($db, $redis)));
        self::assertSame([['status' => 'ok'], ['status' => 'not_ready']], $payloads);
    }
}

<?php
declare(strict_types=1);
namespace ControlPlane\Tests\Unit;

use ControlPlane\Infrastructure\Http\ApiRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiRequestObjectTest extends TestCase
{
    public function testOptionalAndEmptyAddressObjectsAreAccepted(): void
    {
        foreach (['{"legal_name":"Fixture"}', '{"address":{}}'] as $json) {
            self::assertSame([], ApiRequest::optionalObject(Request::create('/', 'PUT', content: $json), 'address'));
        }
        self::assertSame(['city'=>'Perm'], ApiRequest::optionalObject(Request::create('/', 'PUT', content: '{"address":{"city":"Perm"}}'), 'address'));
    }

    #[DataProvider('invalidObjects')]
    public function testArraysNullAndScalarsCannotBecomeAddressObjects(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiRequest::optionalObject(Request::create('/', 'PUT', content: $json), 'address');
    }

    /** @return iterable<array{string}> */
    public static function invalidObjects(): iterable
    {
        foreach (['{"address":[]}','{"address":["Perm"]}','{"address":null}','{"address":"Perm"}','[]'] as $json) {
            yield [$json];
        }
    }
}

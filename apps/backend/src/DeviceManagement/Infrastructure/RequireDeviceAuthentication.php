<?php

declare(strict_types=1);

namespace App\DeviceManagement\Infrastructure;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\DeviceManagement\Application\DeviceIdentity;
use App\DeviceManagement\Application\DeviceStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

/** Authenticates a physical device with its short-lived agent credential. */
final readonly class RequireDeviceAuthentication implements MiddlewareInterface
{
    public function __construct(
        private DeviceStore $store,
        private ResponseFactory $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        $credential = preg_match('/\ABearer ([0-9a-f]{64})\z/i', $authorization, $matches) === 1
            ? strtolower($matches[1]) : null;
        $device = $credential === null ? null : $this->store->credentialDevice(hash('sha256', $credential));
        if ($device === null) {
            return $this->responses
                ->error(ApiMessage::AUTH_REQUIRED, httpCode: Status::UNAUTHORIZED)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }
        return $handler->handle($request->withAttribute(DeviceIdentity::ATTRIBUTE,
            new DeviceIdentity((string) $device['deviceId'], (string) $device['credentialId'], (string) $device['organizationId'])));
    }
}

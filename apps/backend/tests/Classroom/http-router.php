<?php

declare(strict_types=1);

use App\DeviceManagement\Api\DeviceAction;
use App\DeviceManagement\Application\DeviceAccess;
use App\DeviceManagement\Infrastructure\RequireDeviceAuthentication;
use App\Education\Api\PrivateResponse;
use App\Storage\Api\StorageAction;
use App\Storage\Application\StorageAccess;
use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollector;

require __DIR__ . '/http-bootstrap.php';

try {
    $runtime = classroomHttpRuntime();
    $request = (new ServerRequestFactory())->createServerRequest($_SERVER['REQUEST_METHOD'],
        'http://127.0.0.1:18762' . $_SERVER['REQUEST_URI'], $_SERVER)->withQueryParams($_GET);
    foreach (getallheaders() as $name => $value) $request = $request->withHeader($name, $value);
    $input = fopen('php://input', 'rb');
    if ($input === false) throw new RuntimeException('Cannot read the HTTP request.');
    $request = $request->withBody(new Stream($input));
    if (str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
        $raw = (string) $request->getBody();
        $body = $raw === '' ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $request = $request->withParsedBody($body);
    }
    $runtime['requestProvider']->set($request);
    $collector = new RouteCollector();
    foreach (['device-routes.php', 'storage-routes.php'] as $file) {
        $routes = require $runtime['root'] . '/config/common/' . $file;
        foreach ($routes as $route) {
            // The harness deliberately exposes only actual agent routes.
            if (str_starts_with($route->getData('pattern'), '/api/device-agent/')) $collector->addRoute($route);
        }
    }
    $matched = (new UrlMatcher(new RouteCollection($collector)))->match($request);
    if (!$matched->isSuccess()) {
        $response = $runtime['responses']->forStatus($matched->isMethodFailure() ? 405 : 404);
    } else {
        $route = $matched->route();
        $current = new CurrentRoute();
        $current->setUri($request->getUri());
        $current->setRouteWithArguments($route, $matched->arguments());
        $handler = null;
        foreach (array_reverse($route->getData('enabledMiddlewares')) as $definition) {
            $next = $handler;
            $invoke = match ($definition) {
                DeviceAction::class => static fn(ServerRequestInterface $request): ResponseInterface => (new DeviceAction())(
                    $request, $current, $runtime['container']->get(DeviceAccess::class), $runtime['deviceService'], $runtime['responses']),
                StorageAction::class => static fn(ServerRequestInterface $request): ResponseInterface => (new StorageAction())(
                    $request, $current, $runtime['container']->get(StorageAccess::class), $runtime['storageService'], $runtime['responses']),
                PrivateResponse::class => static fn(ServerRequestInterface $request): ResponseInterface => (new PrivateResponse())->process($request, $next),
                RequireDeviceAuthentication::class => static fn(ServerRequestInterface $request): ResponseInterface => (new RequireDeviceAuthentication($runtime['deviceStore'], $runtime['responses']))->process($request, $next),
                default => throw new RuntimeException('Unsupported middleware on an agent route.'),
            };
            $handler = new class($invoke) implements RequestHandlerInterface {
                public function __construct(private Closure $invoke) {}
                public function handle(ServerRequestInterface $request): ResponseInterface { return ($this->invoke)($request); }
            };
        }
        if ($handler === null) throw new RuntimeException('The production route has no handler.');
        $response = $handler->handle($request);
    }
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) foreach ($values as $value) header($name . ': ' . $value, false);
    $output = (string) $response->getBody();
    header('Content-Length: ' . strlen($output));
    header('Connection: close');
    echo $output;
} catch (JsonException) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"error":{"code":"invalid_json"}}';
} catch (Throwable $failure) {
    error_log(get_class($failure) . ': ' . $failure->getMessage() . ' at ' . $failure->getFile() . ':' . $failure->getLine());
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":{"code":"classroom_http_bootstrap_failed"}}';
}


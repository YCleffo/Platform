<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\ParentAction;
use App\Education\Api\PrivateResponse;
use App\Education\Api\EducationAction;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollector;

/** Runs without a database; compares actual registered routes to the published contract. */
final class ParentContractTest extends TestCase
{
    public function testEveryDocumentedFamilyOperationMatchesAnActualRegisteredRoute(): void
    {
        $backend = dirname(__DIR__, 2);
        $root = dirname($backend, 2);
        $document = json_decode((string) file_get_contents($root . '/contracts/openapi/education-parent.yaml'), true, flags: JSON_THROW_ON_ERROR);
        $registered = [];
        foreach (require $backend . '/config/common/education-routes.php' as $route) {
            $middlewares = $route->getData('enabledMiddlewares');
            if (!in_array(ParentAction::class, $middlewares, true)) continue;
            foreach ($route->getData('methods') as $method) $registered[] = $method . ' ' . $route->getData('pattern');
            self::assertContains(PrivateResponse::class, $middlewares, 'Family responses must be private even when they fail.');
            $isRegistration = $route->getData('pattern') === '/api/education/parent/register';
            self::assertSame(!$isRegistration, in_array(RequireAuthentication::class, $middlewares, true));
        }
        $documented = [];
        foreach ($document['paths'] as $path => $definition) {
            foreach ($definition as $method => $operation) {
                if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) continue;
                $documented[] = strtoupper($method) . ' ' . $document['servers'][0]['url'] . $path;
                self::assertArrayHasKey('responses', $operation);
                self::assertNotEmpty($operation['operationId']);
            }
        }
        self::assertNotEmpty($registered);
        self::assertCount(count(array_unique($registered)), $registered, 'No duplicate family method/path registrations.');
        self::assertEqualsCanonicalizing($registered, $documented);
        $this->assertReferencesResolve($document, $document);
    }

    public function testTheParentAreaExposesOnlyReadActionsAndItsOwnInvitationLifecycle(): void
    {
        $backend = dirname(__DIR__, 2);
        foreach (require $backend . '/config/common/parent-routes.php' as $route) {
            $path = $route->getData('pattern');
            if (!str_starts_with($path, '/api/education/parent/')) continue;
            foreach ($route->getData('methods') as $method) {
                if ($method === 'GET') continue;
                self::assertSame('POST', $method);
                self::assertTrue(in_array($path, ['/api/education/parent/activate', '/api/education/parent/register'], true)
                    || $path === '/api/education/parent/children/{studentId}/notifications/{id}/read', $method . ' ' . $path);
            }
        }
    }

    public function testTheCombinedEducationRouterCompilesAndKeepsTeacherAndStudentRoutes(): void
    {
        $collector = new RouteCollector();
        foreach (require dirname(__DIR__, 2) . '/config/common/education-routes.php' as $route) $collector->addRoute($route);
        $matcher = new UrlMatcher(new RouteCollection($collector));
        foreach ([
            ['/api/education/parent/context', ParentAction::class, 'context'],
            ['/api/education/student/home', EducationAction::class, 'student_home'],
            ['/api/education/students', EducationAction::class, 'students'],
        ] as [$path, $action, $operation]) {
            $matched = $matcher->match(new ServerRequest(method: 'GET', uri: 'http://localhost' . $path));
            self::assertTrue($matched->isSuccess(), $path);
            self::assertContains($action, $matched->route()->getData('enabledMiddlewares'));
            self::assertSame($operation, $matched->route()->getData('defaults')['operation']);
        }
    }

    private function assertReferencesResolve(array $node, array $document): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                self::assertStringStartsWith('#/', $value, 'This family contract must be self-contained.');
                $target = $document;
                foreach (explode('/', substr($value, 2)) as $part) {
                    $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                    self::assertIsArray($target);
                    self::assertArrayHasKey($part, $target, 'Unresolved OpenAPI reference: ' . $value);
                    $target = $target[$part];
                }
            } elseif (is_array($value)) $this->assertReferencesResolve($value, $document);
        }
    }
}

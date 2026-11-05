<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Infrastructure\Http\OperatorWorkspaceController;
use ControlPlane\Privacy\ControlPrivacyOperator;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class OperatorWorkspaceTest extends DatabaseTestCase
{
    private function controller(string $id): OperatorWorkspaceController
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new ControlUserPrincipal($id, 'operator@example.test', '!'), 'main'));
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $controller = new OperatorWorkspaceController($this->connection, new ControlPrivacyOperator($this->connection));
        $controller->setContainer($container);
        return $controller;
    }

    public function testCustomerSessionDoesNotImplyOperatorAuthorization(): void
    {
        $response = $this->controller($this->user())->me();
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('operator_authorization_required', json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testPrivacyAndFinanceCapabilitiesRemainIndependentAndRevocable(): void
    {
        $id = $this->user();
        $this->connection->executeStatement('INSERT INTO commercial.privacy_operators (control_user_id, authorization_reference) VALUES (?, ?)', [$id, 'urn:test:privacy']);
        $controller = $this->controller($id);
        $response = $controller->me();
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['privacy']);
        self::assertFalse($data['finance']);
        self::assertSame($id, $data['user']['id']);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->connection->executeStatement('DELETE FROM commercial.privacy_operators WHERE control_user_id=?', [$id]);
        $this->expectException(AccessDeniedException::class);
        $controller->requests(Request::create('/?limit=25&offset=0'));
    }

    public function testPrivacyDirectoriesAreAuthorizedPaginatedAndDoNotExposeEvidence(): void
    {
        $id = $this->user();
        $this->connection->executeStatement('INSERT INTO commercial.privacy_operators (control_user_id, authorization_reference) VALUES (?, ?)', [$id, 'urn:test:privacy']);
        $controller = $this->controller($id);
        foreach (['requests', 'incidents'] as $method) {
            $response = $controller->$method(Request::create('/?limit=10&offset=0'));
            $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($data['items']);
            self::assertIsInt($data['total']);
            self::assertLessThanOrEqual(10, count($data['items']));
            self::assertStringNotContainsString('identity_evidence_reference', (string) $response->getContent());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        $this->expectException(AccessDeniedException::class);
        $controller->compensations(Request::create('/'));
    }

    public function testDirectoriesRejectOversizedPageBeforeQuery(): void
    {
        $id = $this->user();
        $this->connection->executeStatement('INSERT INTO commercial.privacy_operators (control_user_id, authorization_reference) VALUES (?, ?)', [$id, 'urn:test:privacy']);
        $this->expectException(\InvalidArgumentException::class);
        $this->controller($id)->requests(Request::create('/?limit=101'));
    }

    public function testFinancialDirectoryUsesTheActualPlanChangeSchemaAndLiveAuthorization(): void
    {
        $id = $this->user();
        $this->connection->executeStatement('INSERT INTO commercial.financial_operators (control_user_id, authorization_reference) VALUES (?, ?)', [$id, 'urn:test:finance']);
        $controller = $this->controller($id);
        $capabilities = json_decode((string) $controller->me()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($capabilities['finance']);
        self::assertFalse($capabilities['privacy']);
        $response = $controller->compensations(Request::create('/?limit=10&offset=0'));
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data['items']);
        self::assertIsInt($data['total']);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->connection->executeStatement('UPDATE commercial.financial_operators SET revoked_at=now() WHERE control_user_id=?', [$id]);
        $this->expectException(AccessDeniedException::class);
        $controller->compensations(Request::create('/'));
    }
}

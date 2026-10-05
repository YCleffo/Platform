<?php
declare(strict_types=1);
namespace ControlPlane\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
final class AuthOpenApiParityTest extends TestCase
{
    public function testAuthRoutesAndOpenApiPathsRemainInParity(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/src/Infrastructure/Http/AuthController.php');
        preg_match_all("/#\[Route\('([^']+)'[^\]]*methods: \['([A-Z]+)'\]\)\]/",$source,$matches,PREG_SET_ORDER);
        $routes=[]; foreach($matches as $match){$routes[$match[2].' /api/control/auth'.$match[1]]=true;}
        $path=dirname(__DIR__,4).'/contracts/openapi/control-plane.yaml';
        if (!is_file($path)) { self::markTestSkipped('Repository-level OpenAPI contract is not included in the isolated Control Plane image.'); }
        $contract=Yaml::parseFile($path);
        self::assertIsArray($contract); $documented=[]; foreach(($contract['paths'] ?? []) as $path => $operations){if(!str_starts_with($path,'/api/control/auth/')||!is_array($operations))continue;foreach($operations as $method => $_){if(in_array(strtoupper((string)$method),['GET','POST','DELETE','PATCH','PUT'],true))$documented[strtoupper((string)$method).' '.$path]=true;}}
        $routePaths=array_keys($routes); $documentedPaths=array_keys($documented); sort($routePaths); sort($documentedPaths);
        self::assertSame($routePaths,$documentedPaths,'Control auth routes and OpenAPI paths must match exactly.');
    }
}

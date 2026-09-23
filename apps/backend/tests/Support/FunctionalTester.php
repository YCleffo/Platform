<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Environment;
use Codeception\Actor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;

/**
 * Inherited Methods
 *
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause($vars = [])
 *
 * @SuppressWarnings(PHPMD)
 */
class FunctionalTester extends Actor
{
    use _generated\FunctionalTesterActions;

    private static ?HttpApplicationRunner $runner = null;

    public static function getRunner(): HttpApplicationRunner
    {
        if (self::$runner === null) {
            self::$runner = new HttpApplicationRunner(
                rootPath: dirname(__DIR__, 2),
                environment: Environment::appEnv(),
            );
        }

        return self::$runner;
    }

    public function sendRequest(ServerRequestInterface $request): ResponseInterface
    {
        $response = self::getRunner()->runAndGetResponse($request);

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $response;
    }
}

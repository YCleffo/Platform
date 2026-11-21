<?php

declare(strict_types=1);

namespace App\Tests\Classroom;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Api\PersonalDataExportAction;
use App\Core\Privacy\Application\DataSubjectRequestExecutionService;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\PagedPersonalDataContributor;
use App\Core\Privacy\Application\PersonalDataContributorRegistry;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataExporter;
use App\Core\Privacy\Application\PersonalDataExportService;
use App\Core\Privacy\Application\PersonalDataSection;
use App\Core\Privacy\Application\RepresentativeAuthorityPolicy;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\PlatformRuntime\RuntimeMigrationArtifact;
use DomainException;
use HttpSoft\Message\ResponseFactory as PsrResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

/** Explicitly selected Core database; never uses or resets the main platform database. */
final class PrivacyStreamPostgresTest extends TestCase
{
    private static ContainerInterface $container;
    private static ConnectionInterface $db;
    private TransactionInterface $transaction;
    private AuthenticatedIdentity $subject;
    private AuthenticatedIdentity $outsider;
    private string $requestId;

    public static function setUpBeforeClass(): void
    {
        self::assertSame('classroom02_privacy_test', getenv('CLASSROOM_PRIVACY_DATABASE'));
        self::assertSame('classroom02_privacy_test', getenv('DB_NAME'));
        Environment::prepare();
        self::$container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        self::$db = self::$container->get(ConnectionInterface::class);
        self::assertSame('classroom02_privacy_test', self::$db->createCommand('SELECT current_database()')->queryScalar());
        $directory = dirname(__DIR__, 2) . '/migrations/sql';
        $available = (new RuntimeMigrationArtifact(self::$db))->validateHistory($directory);
        self::$db->createCommand('CREATE TABLE IF NOT EXISTS public.platform_migrations(migration_name text PRIMARY KEY,applied_at timestamptz NOT NULL DEFAULT now())')->execute();
        foreach ($available as $name) {
            if (self::$db->createCommand('SELECT 1 FROM public.platform_migrations WHERE migration_name=:name', [':name' => $name])->queryScalar()) {
                continue;
            }
            self::$db->transaction(static function () use ($directory, $name): void {
                // Canonical multi-statement SQL, as used by the module migration fixtures.
                self::$db->getActivePdo()->exec(file_get_contents($directory . '/' . $name));
                self::$db->createCommand('INSERT INTO public.platform_migrations(migration_name) VALUES(:name)', [':name' => $name])->execute();
            });
        }
    }

    protected function setUp(): void
    {
        $this->transaction = self::$db->beginTransaction();
        $this->subject = $this->account('Subject');
        $this->outsider = $this->account('Outsider');
        $requests = self::$container->get(DataSubjectRequestService::class);
        $this->requestId = $requests->submitSelf($this->subject, 'export');
        $requests->transition($this->subject, $this->requestId, 'in_review', null);
        $requests->transition($this->subject, $this->requestId, 'in_progress', null);
    }

    protected function tearDown(): void
    {
        if ($this->transaction->isActive()) {
            $this->transaction->rollBack();
        }
    }

    public function testActionStreamsLargeResponseAndPersistsExactDigestEvidence(): void
    {
        $response = $this->response($this->subject, $this->exporter());
        try {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
            $stream = $response->getBody();
            self::assertTrue($stream->isSeekable());
            self::assertGreaterThan(1048576, $stream->getSize());
            $hash = hash_init('sha256');
            while (!$stream->eof()) {
                hash_update($hash, $stream->read(65536));
            }
            $sha256 = hash_final($hash);
            self::assertSame($sha256, $response->getHeaderLine('X-Content-Sha256'));
            self::assertSame((string) $stream->getSize(), $response->getHeaderLine('Content-Length'));
            $evidence = $this->evidence();
            self::assertSame('completed', $evidence['status']);
            self::assertSame($sha256, $evidence['result_sha256']);
            self::assertSame($stream->getSize(), (int) $evidence['result_size_bytes']);
            self::assertSame('completed', $this->requestStatus());
        } finally {
            $response->getBody()->close();
        }
    }

    public function testAnotherAccountCannotObtainStreamOrCompleteRequest(): void
    {
        $response = $this->response($this->outsider, $this->exporter());
        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->evidence());
        self::assertSame('in_progress', $this->requestStatus());
    }

    public function testStreamLimitRecordsFailureAndLeavesRequestRetryable(): void
    {
        $before = count(get_resources('stream'));
        $response = $this->response($this->subject, $this->exporter(limit: 10000));
        self::assertSame(413, $response->getStatusCode());
        unset($response);
        self::assertSame($before, count(get_resources('stream')));
        self::assertSame('export_too_large', $this->evidence()['error_code']);
        self::assertSame('in_progress', $this->requestStatus());
    }

    public function testLateReadFailureCannotIssuePartialResponseOrSuccessEvidence(): void
    {
        $before = count(get_resources('stream'));
        $response = $this->response($this->subject, $this->exporter(fail: true));
        self::assertSame(404, $response->getStatusCode());
        unset($response);
        self::assertSame($before, count(get_resources('stream')));
        self::assertSame('export_failed', $this->evidence()['error_code']);
        self::assertSame('in_progress', $this->requestStatus());
    }

    private function response(AuthenticatedIdentity $actor, PersonalDataExporter $exporter): ResponseInterface
    {
        $container = self::$container;
        $service = new PersonalDataExportService(
            self::$db,
            $container->get(ClockInterface::class),
            $container->get(RepresentativeAuthorityPolicy::class),
            $exporter,
            $container->get(DataSubjectRequestExecutionService::class),
        );
        $route = new CurrentRoute();
        $route->setRouteWithArguments(Route::get('/test'), ['id' => $this->requestId]);
        $request = (new ServerRequest())->withAttribute(AuthenticatedIdentity::ATTRIBUTE, $actor);
        return (new PersonalDataExportAction())(
            $request,
            $route,
            $service,
            new PsrResponseFactory(),
            new StreamFactory(),
            $container->get(ResponseFactory::class)
        );
    }

    private function exporter(int $limit = 1073741824, bool $fail = false): PersonalDataExporter
    {
        $contributor = new class ($fail) implements PagedPersonalDataContributor {
            public function __construct(private bool $fail) {}
            public function code(): string
            {
                return 'test.stream';
            }
            public function categories(): array
            {
                return ['identity'];
            }
            public function collect(PersonalDataExportContext $context): PersonalDataSection
            {
                throw new \LogicException('Use pages.');
            }
            public function pages(PersonalDataExportContext $context): iterable
            {
                for ($i = 0; $i < 400; $i++) {
                    yield new PersonalDataSection($this->code(), $this->categories(), [['i' => $i, 'content' => str_repeat('x', 4096)]]);
                    if ($this->fail) {
                        throw new DomainException('Binary checksum mismatch.');
                    }
                }
            }
        };
        return new PersonalDataExporter(new PersonalDataContributorRegistry([$contributor]), maxStreamBytes: $limit);
    }

    private function account(string $name): AuthenticatedIdentity
    {
        $person = (string) self::$db->createCommand("INSERT INTO core.persons(given_name,family_name) VALUES(:name,'Stream regression') RETURNING id::text", [':name' => $name])->queryScalar();
        $account = (string) self::$db->createCommand("INSERT INTO core.accounts(person_id,account_type,status) VALUES(CAST(:person AS uuid),'human','active') RETURNING id::text", [':person' => $person])->queryScalar();
        return new AuthenticatedIdentity($account, $person, '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', [], [], null, null);
    }

    private function evidence(): ?array
    {
        return self::$db->createCommand('SELECT status,result_sha256,result_size_bytes,error_code FROM core.data_subject_request_executions WHERE request_id=CAST(:id AS uuid)', [':id' => $this->requestId])->queryOne();
    }

    private function requestStatus(): string
    {
        return (string) self::$db->createCommand('SELECT status FROM core.data_subject_requests WHERE id=CAST(:id AS uuid)', [':id' => $this->requestId])->queryScalar();
    }
}

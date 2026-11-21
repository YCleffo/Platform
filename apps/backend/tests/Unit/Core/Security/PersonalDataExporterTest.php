<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\PersonalDataContributor;
use App\Core\Privacy\Application\PersonalDataContributorRegistry;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataExporter;
use App\Core\Privacy\Application\PersonalDataSection;
use App\Core\Privacy\Application\PagedPersonalDataContributor;
use App\Core\Privacy\Application\PersonalDataExportLimitException;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PersonalDataExporterTest extends TestCase
{
    public function testRegistryOrderCanonicalDigestAndLimits(): void
    {
        $a = $this->contributor('core.a', [['z' => 'value', 'a' => 1]]);
        $b = $this->contributor('core.b', []);
        $context = new PersonalDataExportContext(
            'person',
            'request',
            'person',
            'account',
            null,
            new DateTimeImmutable('2026-09-29T10:00:00Z'),
        );
        $first = (new PersonalDataExporter(new PersonalDataContributorRegistry([$b, $a])))->export($context);
        $second = (new PersonalDataExporter(new PersonalDataContributorRegistry([$a, $b])))->export($context);
        self::assertSame($first->sha256, $second->sha256);
        self::assertSame('core.a', $first->sections[0]->contributorCode);
        self::assertSame(strlen($first->json), $first->sizeBytes);
        self::assertSame(hash('sha256', $first->json), $first->sha256);
        $this->expectException(DomainException::class);
        (new PersonalDataExporter(new PersonalDataContributorRegistry([$a, $b]), maxTotalBytes: 10))->export($context);
    }

    public function testDuplicateContributorRejected(): void
    {
        $this->expectException(DomainException::class);
        new PersonalDataContributorRegistry([$this->contributor('core.a', []), $this->contributor('core.a', [])]);
    }

    public function testStreamPreservesCanonicalSchemaAndTransfersTemporaryFileOwnership(): void
    {
        $exporter = new PersonalDataExporter(new PersonalDataContributorRegistry([
            $this->contributor('core.b', []),
            $this->contributor('core.a', [['z' => 'текст/é', 'a' => ['second' => 2, 'first' => true]]]),
        ]));
        $context = $this->context();
        $small = $exporter->export($context);
        $artifact = $exporter->exportToStream($context);
        $stream = $artifact->detachStream();
        $path = stream_get_meta_data($stream)['uri'];
        try {
            self::assertSame($small->json, stream_get_contents($stream));
            self::assertSame($small->sha256, $artifact->sha256);
            self::assertSame($small->sizeBytes, $artifact->sizeBytes);
            self::assertSame($context->subjectPersonId, $artifact->subjectPersonId);
            unset($artifact);
            self::assertTrue(is_resource($stream), 'The response owns a detached stream.');
        } finally {
            fclose($stream);
        }
        self::assertFileDoesNotExist($path, 'Temporary personal data must not remain on disk.');
    }

    public function testStreamProcessesLargePagesWithoutRetainingEarlierSections(): void
    {
        $contributor = new class implements PagedPersonalDataContributor {
            public int $count = 0;
            public ?\WeakReference $previous = null;
            public function code(): string
            {
                return 'test.large';
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
                for ($i = 0; $i < 600; $i++) {
                    if ($this->previous?->get() !== null) {
                        throw new \LogicException('Exporter retained an older section.');
                    }
                    $section = new PersonalDataSection($this->code(), $this->categories(), [['i' => $i, 'content' => str_repeat('x', 4096)]]);
                    yield $section;
                    $this->previous = \WeakReference::create($section);
                    unset($section);
                    // The consumer may retain the current page until the next yield, never all pages.
                    yield new PersonalDataSection($this->code(), $this->categories(), []);
                    $this->count++;
                }
            }
        };
        $exporter = new PersonalDataExporter(new PersonalDataContributorRegistry([$contributor]));
        $artifact = $exporter->exportToStream($this->context());
        $stream = $artifact->detachStream();
        try {
            self::assertSame(600, $contributor->count);
            self::assertGreaterThan(2097152, $artifact->sizeBytes);
            $hash = hash_init('sha256');
            self::assertSame($artifact->sizeBytes, hash_update_stream($hash, $stream));
            self::assertSame($artifact->sha256, hash_final($hash));
        } finally {
            fclose($stream);
        }
    }

    public function testStreamLimitClosesTemporaryArtifactAndStopsProducer(): void
    {
        $contributor = new class implements PagedPersonalDataContributor {
            public bool $closed = false;
            public function code(): string
            {
                return 'test.limit';
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
                try {
                    for ($i = 0; $i < 20; $i++) {
                        yield new PersonalDataSection($this->code(), $this->categories(), [['content' => str_repeat('x', 4096)]]);
                    }
                } finally {
                    $this->closed = true;
                }
            }
        };
        $before = count(get_resources('stream'));
        try {
            (new PersonalDataExporter(new PersonalDataContributorRegistry([$contributor]), maxStreamBytes: 6000))->exportToStream($this->context());
            self::fail('Partial oversized export was issued.');
        } catch (PersonalDataExportLimitException) {
            self::assertTrue($contributor->closed);
            self::assertSame($before, count(get_resources('stream')), 'Failed artifact resource leaked.');
        }
    }

    public function testStreamRejectsLateContributorFailureWithoutLeakingArtifact(): void
    {
        $contributor = new class implements PagedPersonalDataContributor {
            public function code(): string
            {
                return 'test.failure';
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
                yield new PersonalDataSection($this->code(), $this->categories(), [['value' => 'valid first page']]);
                throw new DomainException('Binary checksum mismatch.');
            }
        };
        $before = count(get_resources('stream'));
        try {
            (new PersonalDataExporter(new PersonalDataContributorRegistry([$contributor])))->exportToStream($this->context());
            self::fail('Partial unverified export was issued.');
        } catch (DomainException $error) {
            self::assertSame('Binary checksum mismatch.', $error->getMessage());
            self::assertSame($before, count(get_resources('stream')));
        }
    }

    private function context(): PersonalDataExportContext
    {
        return new PersonalDataExportContext('person', 'request', 'person', 'account', null, new DateTimeImmutable('2026-11-13T00:00:00Z'));
    }

    /** @param list<array<string, mixed>> $records */
    private function contributor(string $code, array $records): PersonalDataContributor
    {
        return new class ($code, $records) implements PersonalDataContributor {
            /** @param list<array<string, mixed>> $records */
            public function __construct(private string $code, private array $records) {}
            public function code(): string
            {
                return $this->code;
            }
            public function categories(): array
            {
                return ['identity'];
            }
            public function collect(PersonalDataExportContext $context): PersonalDataSection
            {
                return new PersonalDataSection($this->code, $this->categories(), $this->records);
            }
        };
    }
}

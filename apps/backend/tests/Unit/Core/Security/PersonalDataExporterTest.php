<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\PersonalDataContributor;
use App\Core\Privacy\Application\PersonalDataContributorRegistry;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataExporter;
use App\Core\Privacy\Application\PersonalDataSection;
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

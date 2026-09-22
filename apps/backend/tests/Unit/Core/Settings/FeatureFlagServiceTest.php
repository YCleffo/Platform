<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Settings;

use App\Core\Module\Application\ModuleRepository;
use App\Core\Module\Application\ModuleView;
use App\Core\Settings\Application\FeatureFlag;
use App\Core\Settings\Application\FeatureFlagRepository;
use App\Core\Settings\Application\FeatureFlagService;
use LogicException;
use PHPUnit\Framework\TestCase;

final class FeatureFlagServiceTest extends TestCase
{
    public function testMissingDisabledAndZeroRolloutAreAlwaysDisabled(): void
    {
        $service = $this->service([
            'disabled' => $this->flag('disabled', false, 100),
            'zero' => $this->flag('zero', true, 0),
        ]);

        self::assertFalse($service->isEnabledFor('missing', 'account-1'));
        self::assertFalse($service->isEnabledFor('disabled', 'account-1'));
        self::assertFalse($service->isEnabledFor('zero', 'account-1'));
    }

    public function testFullRolloutIsAlwaysEnabledAndReturnsConfig(): void
    {
        $service = $this->service([
            'full' => $this->flag('full', true, 100, ['variant' => 'stable']),
        ]);

        self::assertTrue($service->isEnabled('full'));
        self::assertTrue($service->isEnabledFor('full', 'account-1'));
        self::assertSame(
            ['variant' => 'stable'],
            $service->evaluate('full', 'account-1')->config,
        );
    }

    public function testPartialRolloutIsStableAndReasonablyDistributed(): void
    {
        $service = $this->service([
            'quarter-a' => $this->flag('quarter-a', true, 25),
            'quarter-b' => $this->flag('quarter-b', true, 25),
        ]);
        $enabled = 0;
        $differentCodes = 0;

        for ($index = 0; $index < 10_000; ++$index) {
            $subject = 'account-' . $index;
            $first = $service->isEnabledFor('quarter-a', $subject);
            self::assertSame($first, $service->isEnabledFor('quarter-a', $subject));
            $enabled += (int) $first;
            $differentCodes += (int) ($first !== $service->isEnabledFor('quarter-b', $subject));
        }

        self::assertGreaterThan(2_200, $enabled);
        self::assertLessThan(2_800, $enabled);
        self::assertGreaterThan(2_000, $differentCodes);
    }

    public function testContextFreeEvaluationRejectsPartialRollout(): void
    {
        $service = $this->service(['partial' => $this->flag('partial', true, 50)]);

        $this->expectException(LogicException::class);
        $service->isEnabled('partial');
    }

    public function testDisabledOwningModuleOverridesEnabledFlag(): void
    {
        $module = $this->module(false);
        $service = $this->service(
            ['owned' => $this->flag('owned', true, 100, moduleCode: $module->code)],
            [$module->code => $module],
        );

        $evaluation = $service->evaluate('owned', 'account-1');

        self::assertFalse($evaluation->enabled);
        self::assertSame([], $evaluation->config);
    }

    /**
     * @param array<string, FeatureFlag> $flags
     * @param array<string, ModuleView> $modules
     */
    private function service(array $flags, array $modules = []): FeatureFlagService
    {
        $flagRepository = $this->createStub(FeatureFlagRepository::class);
        $flagRepository->method('findByCode')->willReturnCallback(
            static fn(string $code): ?FeatureFlag => $flags[$code] ?? null,
        );
        $moduleRepository = $this->createStub(ModuleRepository::class);
        $moduleRepository->method('findByCode')->willReturnCallback(
            static fn(string $code): ?ModuleView => $modules[$code] ?? null,
        );

        return new FeatureFlagService($flagRepository, $moduleRepository);
    }

    /** @param array<array-key, mixed> $config */
    private function flag(
        string $code,
        bool $enabled,
        int $rolloutPercent,
        array $config = [],
        ?string $moduleCode = null,
    ): FeatureFlag {
        return new FeatureFlag(
            id: '01900000-0000-7000-8000-000000000001',
            moduleId: $moduleCode === null ? null : '01900000-0000-7000-8000-000000000002',
            moduleCode: $moduleCode,
            code: $code,
            enabled: $enabled,
            rolloutPercent: $rolloutPercent,
            config: $config,
            description: null,
            updatedByAccountId: null,
            createdAt: '2026-01-01T00:00:00Z',
            updatedAt: '2026-01-01T00:00:00Z',
        );
    }

    private function module(bool $enabled): ModuleView
    {
        return new ModuleView(
            id: '01900000-0000-7000-8000-000000000002',
            code: 'education',
            name: 'Education',
            description: null,
            version: '1.0.0',
            settingsSchemaVersion: 1,
            enabled: $enabled,
            installedAt: '2026-01-01T00:00:00Z',
            enabledAt: null,
            disabledAt: '2026-01-01T00:00:00Z',
        );
    }
}

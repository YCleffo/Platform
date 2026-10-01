<?php

declare(strict_types=1);

namespace App\Tests\Unit\PlatformRuntime;

use App\PlatformRuntime\RuntimeMigrationArtifact;
use DomainException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final class RuntimeMigrationArtifactTest extends TestCase
{
    public function testHistoryFailsClosedWhenAppliedMigrationIsAbsentFromTargetArtifact(): void
    {
        $directory = $this->directory(['001_initial.sql' => 'SELECT 1']);
        $writes = 0;
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willReturnCallback(function (?string $sql) use (&$writes): CommandInterface {
            $command = $this->createMock(CommandInterface::class);
            $command->method('execute')->willReturnCallback(static function () use (&$writes): int { ++$writes; return 1; });
            if (str_contains($sql ?? '', 'to_regclass')) { $command->method('queryScalar')->willReturn('platform_migrations'); }
            if (str_contains($sql ?? '', 'SELECT migration_name')) {
                $command->method('queryColumn')->willReturn(['001_initial.sql', '002_removed.sql']);
            }
            return $command;
        });
        try {
            try { (new RuntimeMigrationArtifact($db))->apply($directory); self::fail('Invalid history was applied.'); }
            catch (DomainException) { self::assertSame(0, $writes); }
        } finally { $this->removeDirectory($directory); }
    }

    public function testValidAppliedHistoryAndNewTargetMigrationAreAllowed(): void
    {
        $directory = $this->directory(['002_new.sql' => 'SELECT 2', '001_applied.sql' => 'SELECT 1']);
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willReturnCallback(function (?string $sql): CommandInterface {
            $command = $this->createMock(CommandInterface::class);
            if (str_contains($sql ?? '', 'to_regclass')) { $command->method('queryScalar')->willReturn('platform_migrations'); }
            if (str_contains($sql ?? '', 'SELECT migration_name')) { $command->method('queryColumn')->willReturn(['001_applied.sql']); }
            return $command;
        });
        try {
            self::assertSame(['001_applied.sql', '002_new.sql'],
                (new RuntimeMigrationArtifact($db))->validateHistory($directory));
        } finally { $this->removeDirectory($directory); }
    }

    public function testEmptyArtifactFailsClosed(): void
    {
        $directory = $this->directory([]);
        $db = $this->createMock(ConnectionInterface::class);
        try {
            $this->expectException(DomainException::class);
            (new RuntimeMigrationArtifact($db))->validateHistory($directory);
        } finally { $this->removeDirectory($directory); }
    }

    public function testApplyIsSortedMonotonicAndIdempotent(): void
    {
        $directory = $this->directory(['002_second.sql' => 'SELECT 2', '001_first.sql' => 'SELECT 1']);
        $applied = []; $executed = [];
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $db->method('createCommand')->willReturnCallback(
            function (?string $sql, array $params = []) use (&$applied, &$executed): CommandInterface {
                $command = $this->createMock(CommandInterface::class);
                if (str_contains($sql ?? '', 'to_regclass')) {
                    $command->method('queryScalar')->willReturnCallback(static fn(): string|false => $applied === [] ? false : 'platform_migrations');
                } elseif (str_contains($sql ?? '', 'SELECT migration_name')) {
                    $command->method('queryColumn')->willReturnCallback(static fn(): array => array_keys($applied));
                } elseif (str_contains($sql ?? '', 'SELECT 1 FROM public.platform_migrations')) {
                    $command->method('queryScalar')->willReturnCallback(static fn(): int|false => isset($applied[$params[':name']]) ? 1 : false);
                } elseif (str_contains($sql ?? '', 'INSERT INTO public.platform_migrations')) {
                    $command->method('execute')->willReturnCallback(static function () use (&$applied, $params): int {
                        $applied[$params[':name']] = true; return 1;
                    });
                } elseif (!str_contains($sql ?? '', 'CREATE TABLE')) {
                    $command->method('execute')->willReturnCallback(static function () use (&$executed, $sql): int {
                        $executed[] = trim((string)$sql); return 1;
                    });
                }
                return $command;
            });
        try {
            $artifact = new RuntimeMigrationArtifact($db);
            $artifact->apply($directory);
            $artifact->apply($directory);
            self::assertSame(['SELECT 1', 'SELECT 2'], $executed);
            self::assertSame(['001_first.sql', '002_second.sql'], array_keys($applied));
        } finally { $this->removeDirectory($directory); }
    }

    /** @param array<string,string> $files */
    private function directory(array $files): string
    {
        $directory = sys_get_temp_dir() . '/runtime-migrations-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        foreach ($files as $name => $contents) { file_put_contents($directory . '/' . $name, $contents); }
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($directory);
    }
}

<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Updates only release metadata for the singleton runtime installation. */
final readonly class RuntimeReleaseMetadataUpdater
{
    public function __construct(private ConnectionInterface $db) {}

    public function apply(string $releaseId, string $version, string $channel): void
    {
        if ($releaseId === '' || $version === '' || !in_array($channel, ['stable', 'beta', 'canary'], true)
            || strlen($releaseId) > 160 || strlen($version) > 120 || preg_match('/[[:cntrl:]]/', $releaseId . $version) === 1) {
            throw new DomainException('Invalid release metadata.');
        }
        $count = $this->db->createCommand(<<<'SQL'
UPDATE platform_runtime.installations SET release_id=:release, application_version=:version,
    release_channel=CAST(:channel AS platform_runtime.release_channel), updated_at=now()
WHERE singleton_key=1
SQL, [':release' => $releaseId, ':version' => $version, ':channel' => $channel])->execute();
        if ($count !== 1) {
            throw new DomainException('Local installation is unavailable.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use LogicException;

/** A fully verified, seekable response; never exposes a partial export. */
final class PersonalDataExportArtifact
{
    /** @var resource|null */
    private $stream;

    /** @param resource $stream */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $generatedAt,
        public readonly string $subjectPersonId,
        public readonly string $sha256,
        public readonly int $sizeBytes,
        $stream,
    ) {
        $this->stream = $stream;
    }

    /** Transfer ownership to the response, whose stream closes the temporary file. @return resource */
    public function detachStream()
    {
        if (!is_resource($this->stream)) {
            throw new LogicException('Export stream has already been detached.');
        }
        $stream = $this->stream;
        $this->stream = null;
        return $stream;
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) fclose($this->stream);
    }
}

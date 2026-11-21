<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Education\Application\FileStore;
use App\Education\Application\HomeworkFileService;
use App\Education\Application\HomeworkService;
use App\Education\Application\Scope;
use App\Education\Infrastructure\S3FileStore;
use App\Tests\Storage02\EducationS3Fixture;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFile;

require_once __DIR__ . '/HomeworkFixture.php';
require_once dirname(__DIR__) . '/Storage02/EducationS3Fixture.php';

final class HomeworkFilesTest extends HomeworkFixture
{
    private HomeworkFileService $files;
    private S3FileStore $privateFiles;
    private array $storedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('1', getenv('STORAGE02_TEST_RUNTIME'));
        $this->privateFiles = $this->container->get(S3FileStore::class);
        $this->files = $this->withClock(
            HomeworkFileService::class,
            [FileStore::class => $this->privateFiles, HomeworkService::class => $this->homework],
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        EducationS3Fixture::removeOwned($this->storedKeys);
    }

    public function testSubmissionFilesArePrivateAndSurviveReturnUnlinkAndResubmission(): void
    {
        $assignment = $this->publish(['type' => 'files']);
        $first = $this->upload($this->learner['scope'], $assignment['id'], 'First answer file');
        $ownDownload = $this->files->download($this->learner['scope'], $first['id']);
        self::assertSame('First answer file', EducationS3Fixture::bytes($ownDownload));
        $this->rejection(404, fn() => $this->files->download($this->peer['scope'], $first['id']));
        $this->rejection(404, fn() => $this->files->download($this->outsider, $first['id']));
        $this->submit($assignment['id']);
        $submitted = $this->detail($assignment['id'])['submission'];
        self::assertSame([$first['id']], array_column($submitted['currentVersion']['files'], 'id'));
        self::assertSame('First answer file', EducationS3Fixture::bytes($this->files->download($this->teacher, $first['id'])));
        $this->rejection(409, fn() => $this->files->unlink($this->learner['scope'], $assignment['id'], $first['id']));

        $this->homework->write(
            'review',
            $this->teacher,
            ['version' => $submitted['version'], 'idempotencyKey' => $this->id(), 'decision' => 'returned', 'comment' => 'Attach an improved file.'],
            $submitted['id'],
        );
        $this->files->unlink($this->learner['scope'], $assignment['id'], $first['id']);
        $second = $this->upload($this->learner['scope'], $assignment['id'], 'Improved answer file');
        $this->submit($assignment['id']);
        $versions = array_column($this->detail($assignment['id'])['submission']['versions'], null, 'sequence');
        self::assertSame([$first['id']], array_column($versions[1]['files'], 'id'));
        self::assertSame([$second['id']], array_column($versions[2]['files'], 'id'));
        self::assertSame('First answer file', EducationS3Fixture::bytes($this->files->download($this->learner['scope'], $first['id'])));
        self::assertSame('Improved answer file', EducationS3Fixture::bytes($this->files->download($this->teacher, $second['id'])));
    }

    public function testMaterialsRequirePublicationAndAnAssignmentRecipient(): void
    {
        $assignment = $this->draft(['groupId' => null, 'studentIds' => [$this->learner['id']]]);
        $material = $this->upload($this->teacher, $assignment['id'], 'Teacher instructions', '../../instructions.txt');
        self::assertSame('instructions.txt', $material['name']);
        $this->rejection(404, fn() => $this->files->download($this->learner['scope'], $material['id']));
        $this->homework->write('publish', $this->teacher, ['version' => $assignment['version']], $assignment['id']);
        self::assertSame('Teacher instructions', EducationS3Fixture::bytes($this->files->download($this->learner['scope'], $material['id'])));
        $this->rejection(404, fn() => $this->files->download($this->peer['scope'], $material['id']));
        $this->rejection([403,404], fn() => $this->files->download($this->colleague, $material['id']));
        $this->rejection(409, fn() => $this->upload($this->teacher, $assignment['id'], 'Material changed after publication'));
        // A random personal Storage UUID is never resolved as a Homework blob.
        $this->rejection(404, fn() => $this->files->download($this->learner['scope'], $this->id()));
    }

    public function testMimeAndDeclaredAndStreamedSizeLimitsRejectWithoutMetadataOrOrphanBytes(): void
    {
        $assignment = $this->publish(['type' => 'files']);
        $html = '<!doctype html><html><script>alert(document.cookie)</script></html>';
        $this->rejection(422, fn() => $this->files->upload(
            $this->learner['scope'],
            $assignment['id'],
            $this->uploadValue($html, 'safe.pdf', 'application/pdf'),
        ), 'invalid_file');
        $this->rejection(422, fn() => $this->files->upload(
            $this->learner['scope'],
            $assignment['id'],
            $this->uploadValue('small bytes', 'large.txt', 'text/plain', 10 * 1024 * 1024 + 1),
        ), 'invalid_file');
        $this->rejection(422, fn() => $this->files->upload(
            $this->learner['scope'],
            $assignment['id'],
            $this->uploadValue(str_repeat('x', 10 * 1024 * 1024 + 1), 'lying-size.txt', 'text/plain', 1),
        ), 'invalid_file');
        self::assertSame(0, $this->rowCount('homework_files', 'assignment_id', $assignment['id']));
        $this->rejection(422, fn() => $this->privateFiles->open('../outside.txt', 1, str_repeat('0', 64)));
        $this->rejection(422, fn() => $this->files->download($this->learner['scope'], '../../storage/documents'));
    }

    private function upload(Scope $scope, string $assignment, string $bytes, string $name = 'answer.txt'): array
    {
        $file = $this->files->upload($scope, $assignment, $this->uploadValue($bytes, $name));
        $this->storedKeys[] = (string) $this->db->createCommand('SELECT storage_key::text FROM education.homework_files WHERE id=CAST(:id AS uuid)', [':id' => $file['id']])->queryScalar();
        return $file;
    }

    private function uploadValue(string $bytes, string $name, string $mime = 'text/plain', ?int $declaredSize = null): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($bytes), $declaredSize ?? strlen($bytes), UPLOAD_ERR_OK, $name, $mime);
    }
}

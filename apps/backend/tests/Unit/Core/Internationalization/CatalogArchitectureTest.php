<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Internationalization;

use App\Api\Shared\ApiMessage;
use Codeception\Test\Unit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class CatalogArchitectureTest extends Unit
{
    public function testTransactionalEmailTemplatesHaveStrictLocaleParity(): void
    {
        $root = dirname(__DIR__, 4) . '/resources/email';
        $english = $this->emailTemplates($root . '/en-US');
        $russian = $this->emailTemplates($root . '/ru-RU');

        self::assertSame($english, $russian, 'en-US and ru-RU email templates must have identical files.');
        foreach ($english as $code => $extensions) {
            self::assertSame(
                ['html', 'subject.txt', 'txt'],
                $extensions,
                sprintf('Template "%s" must provide subject, HTML, and plain-text variants.', $code),
            );
        }
    }

    public function testRequiredCatalogsHaveValidParityAndCoverApiMessageIds(): void
    {
        $root = dirname(__DIR__, 4);
        $english = $this->catalog($root . '/resources/messages/en-US/app.php');
        $russian = $this->catalog($root . '/resources/messages/ru-RU/app.php');

        $englishKeys = array_keys($english);
        $russianKeys = array_keys($russian);
        sort($englishKeys);
        sort($russianKeys);

        self::assertSame($englishKeys, $russianKeys, 'en-US and ru-RU catalogs must have identical IDs.');
        foreach ([$english, $russian] as $catalog) {
            foreach ($catalog as $id => $message) {
                self::assertIsString($id);
                self::assertIsString($message);
                self::assertNotSame('', trim($message));
                self::assertTrue(mb_check_encoding($message, 'UTF-8'));
            }
        }

        foreach ((new ReflectionClass(ApiMessage::class))->getConstants() as $messageId) {
            if (is_string($messageId)) {
                self::assertArrayHasKey($messageId, $english, "Missing API message ID: $messageId");
            }
        }
    }

    public function testApiBoundaryDoesNotExposeHardcodedMessages(): void
    {
        $root = dirname(__DIR__, 4) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_contains($path, '/Api/') && !str_contains($path, '/Infrastructure/Require')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/->fail\s*\(/', $source, $path);
            self::assertDoesNotMatchRegularExpression('/error_message/', $source, $path);
            self::assertDoesNotMatchRegularExpression('/\$exception->getMessage\s*\(/', $source, $path);
            self::assertDoesNotMatchRegularExpression('/addError\s*\(\s*[\'\"]/', $source, $path);
            self::assertDoesNotMatchRegularExpression(
                '/\$(?:responses?|responseFactory|apiResponseFactory)->(?:error|notFound)\s*\(\s*[\'\"]/',
                $source,
                $path,
            );
            self::assertDoesNotMatchRegularExpression('/[\'\"]message[\'\"]\s*=>\s*[\'\"][A-Z]/', $source, $path);
        }
    }

    /** @return array<string, string> */
    private function catalog(string $path): array
    {
        self::assertFileExists($path);
        $catalog = require $path;
        self::assertIsArray($catalog);

        /** @var array<string, string> $catalog */
        return $catalog;
    }

    /** @return array<string, list<string>> */
    private function emailTemplates(string $directory): array
    {
        self::assertDirectoryExists($directory);
        $templates = [];
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (!is_file($path) || preg_match('/\A(.+?)\.(subject\.txt|html|txt)\z/', basename($path), $matches) !== 1) {
                continue;
            }
            $templates[$matches[1]][] = $matches[2];
        }
        foreach ($templates as &$extensions) {
            sort($extensions);
        }
        unset($extensions);
        ksort($templates);

        return $templates;
    }
}

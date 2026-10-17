<?php

namespace Tests\Unit;

use App\Services\WordPress\WordPressUploadsArchive;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class WordPressUploadsArchiveTest extends TestCase
{
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_extracts_only_supported_images_from_an_uploads_zip(): void
    {
        $archive = $this->zip([
            'wordpress/wp-content/uploads/2024/05/photo.jpg' => 'jpeg-bytes',
            'wordpress/wp-content/uploads/2024/05/unused.png' => 'png-bytes',
            'wordpress/wp-content/uploads/2024/05/document.pdf' => 'pdf-bytes',
        ]);
        $destination = $this->temporaryDirectory();

        $result = (new WordPressUploadsArchive)->extract($archive, $destination, ['2024/05/photo.jpg']);

        $this->assertSame(1, $result['files']);
        $this->assertSame($destination.DIRECTORY_SEPARATOR.'wordpress'.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'uploads', $result['directory']);
        $this->assertFileExists($result['directory'].DIRECTORY_SEPARATOR.'2024'.DIRECTORY_SEPARATOR.'05'.DIRECTORY_SEPARATOR.'photo.jpg');
        $this->assertFileDoesNotExist($result['directory'].DIRECTORY_SEPARATOR.'2024'.DIRECTORY_SEPARATOR.'05'.DIRECTORY_SEPARATOR.'unused.png');
        $this->assertFileDoesNotExist($result['directory'].DIRECTORY_SEPARATOR.'2024'.DIRECTORY_SEPARATOR.'05'.DIRECTORY_SEPARATOR.'document.pdf');
    }

    public function test_it_rejects_directory_traversal(): void
    {
        $archive = $this->zip(['../outside.jpg' => 'unsafe']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不正な相対パス');

        (new WordPressUploadsArchive)->extract($archive, $this->temporaryDirectory(), ['outside.jpg']);
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $directory = $this->temporaryDirectory();
        $path = $directory.DIRECTORY_SEPARATOR.'uploads.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'setup-cms-'.bin2hex(random_bytes(8));
        mkdir($path, 0755, true);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$item;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}

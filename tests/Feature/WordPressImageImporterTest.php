<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\WordPress\WordPressImageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WordPressImageImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_copies_and_sanitizes_an_image_from_wordpress_uploads(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $uploads = storage_path('framework/testing/wordpress-uploads');
        $sourceDirectory = $uploads.DIRECTORY_SEPARATOR.'2024'.DIRECTORY_SEPARATOR.'05';
        File::ensureDirectoryExists($sourceDirectory);
        $fake = UploadedFile::fake()->image('photo.jpg', 320, 180);
        $source = $sourceDirectory.DIRECTORY_SEPARATOR.'photo.jpg';
        File::copy($fake->getRealPath(), $source);

        $media = null;
        try {
            $media = app(WordPressImageImporter::class)->import(
                'https://old.example.com/wp-content/uploads/2024/05/photo.jpg',
                $uploads,
                $admin->id,
                '移行画像',
            );

            $this->assertInstanceOf(Media::class, $media);
            $this->assertSame('移行画像', $media->alt_text);
            $this->assertSame(320, $media->width);
            $this->assertSame(180, $media->height);
            Storage::disk('public')->assertExists($media->path);
        } finally {
            if ($media) {
                Storage::disk('public')->delete($media->path);
            }
            File::deleteDirectory($uploads);
        }
    }
}

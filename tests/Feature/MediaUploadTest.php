<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_an_image_as_json_for_the_markdown_editor(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->post('/admin/media', [
            'image' => UploadedFile::fake()->image('article-image.jpg', 640, 360),
            'alt_text' => '記事内の説明画像',
        ], ['Accept' => 'application/json']);

        $media = Media::query()->sole();

        try {
            $response
                ->assertCreated()
                ->assertJsonPath('data.id', $media->id)
                ->assertJsonPath('data.alt', '記事内の説明画像')
                ->assertJsonPath('data.width', 640)
                ->assertJsonPath('data.height', 360);

            $this->assertStringEndsWith('/storage/'.$media->path, $response->json('data.url'));
            Storage::disk('public')->assertExists($media->path);
        } finally {
            Storage::disk('public')->delete($media->path);
        }
    }

    public function test_image_upload_json_response_keeps_validation_errors_structured(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->post('/admin/media', [
            'image' => UploadedFile::fake()->create('unsafe.svg', 5, 'image/svg+xml'),
            'alt_text' => '安全でない画像',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_oversized_post_returns_a_clear_image_upload_error(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->withServerVariables(['CONTENT_LENGTH' => 13 * 1024 * 1024])
            ->actingAs($user)
            ->postJson('/admin/media')
            ->assertStatus(413)
            ->assertJsonPath('errors.image.0', 'アップロードできる画像のファイルサイズは10MBまでです。');
    }
}

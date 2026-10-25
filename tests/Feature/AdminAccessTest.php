<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/posts')->assertRedirect('/login');
    }

    public function test_cms_pages_are_marked_as_noindex(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet')
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">', false);

        $this->get('/api/v1/posts')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

        $this->assertStringContainsString('Disallow: /', file_get_contents(public_path('robots.txt')));
    }

    public function test_authenticated_admin_can_open_posts(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user)->get('/admin/posts')->assertOk();
    }

    public function test_post_editor_has_single_surface_markdown_editor_and_commands(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        Media::create([
            'disk' => 'public',
            'path' => '2026/10/reusable-image.jpg',
            'original_name' => 'reusable-image.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'width' => 640,
            'height' => 360,
            'alt_text' => '再利用できる画像',
            'sha256' => str_repeat('a', 64),
            'uploaded_by' => $user->id,
        ]);

        $this->actingAs($user)->get('/admin/posts/create')
            ->assertOk()
            ->assertSee('data-unsaved-changes-form', false)
            ->assertSee('data-rich-markdown-editor', false)
            ->assertSee('data-markdown', false)
            ->assertSee('data-markdown-command="h2"', false)
            ->assertSee('data-markdown-command="h3"', false)
            ->assertSee('data-markdown-command="ul"', false)
            ->assertSee('data-markdown-command="ol"', false)
            ->assertSee('data-image-command', false)
            ->assertSee('data-media-dialog', false)
            ->assertSee('data-image-upload-trigger', false)
            ->assertSee('data-media-select', false)
            ->assertSee('data-media-url="http://localhost/storage/2026/10/reusable-image.jpg"', false)
            ->assertSee('data-featured-picker', false)
            ->assertSee('data-featured-dialog', false)
            ->assertSee('data-featured-select', false)
            ->assertSee('data-featured-upload', false)
            ->assertSee('type="hidden" name="featured_image_id"', false)
            ->assertDontSee('<select name="featured_image_id">', false)
            ->assertSee('再利用できる画像')
            ->assertDontSee('required data-alt-input', false)
            ->assertDontSee('data-preview', false)
            ->assertDontSee('preview-panel', false);
    }

    public function test_password_login_goes_directly_to_posts(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => 'password123',
            'is_admin' => true,
        ]);

        $this->post('/login', [
            'email' => 'admin@example.test',
            'password' => 'password123',
        ])->assertRedirect(route('admin.posts.index'));

        $this->assertAuthenticated();
    }
}

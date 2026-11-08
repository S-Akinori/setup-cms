<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PreviewFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_the_frontend_preview_url(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('プレビューURL')
            ->assertSee('cms_preview_url');

        $this->actingAs($admin)->put('/admin/settings', [
            'preview_url' => 'https://blog.example.com/preview',
        ])->assertRedirect()->assertSessionHas('success', '設定を保存しました。');

        $this->assertSame('https://blog.example.com/preview', Setting::valueOf('preview_url'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
    }

    public function test_preview_url_must_use_http_or_https(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put('/admin/settings', [
            'preview_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('preview_url');

        $this->assertNull(Setting::valueOf('preview_url'));
    }

    public function test_admin_can_open_a_draft_through_a_temporary_signed_preview(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $post = Post::create([
            'title' => '下書きプレビュー',
            'slug' => 'draft-preview',
            'body_markdown' => '# 公開前の本文',
            'status' => 'draft',
            'author_id' => $admin->id,
        ]);
        Setting::setValue('preview_url', 'https://blog.example.com/preview?theme=light');

        $response = $this->actingAs($admin)->get(route('admin.preview', $post));
        $response->assertRedirect()->assertHeader('Location');

        $location = $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('light', $query['theme']);
        $this->assertSame('draft-preview', $query['slug']);
        $this->assertSame('post', $query['type']);

        $signedUrl = $query['cms_preview_url'];
        $signedPath = parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY);
        $previewResponse = $this->withHeader('Origin', 'https://blog.example.com')->getJson($signedPath);
        $previewResponse
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertJsonPath('data.slug', 'draft-preview')
            ->assertJsonPath('data.bodyMarkdown', '# 公開前の本文')
            ->assertJsonPath('meta.preview', true);
        $this->assertStringContainsString('private', (string) $previewResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $previewResponse->headers->get('Cache-Control'));
    }

    public function test_unsigned_or_tampered_preview_requests_are_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $post = Post::create([
            'title' => '署名テスト',
            'slug' => 'signature-test',
            'body_markdown' => '非公開本文',
            'status' => 'draft',
            'author_id' => $admin->id,
        ]);

        $this->getJson("/api/v1/preview/{$post->id}")->assertForbidden();

        $signedUrl = URL::temporarySignedRoute('api.preview', now()->addMinutes(15), ['post' => $post->id]);
        $tamperedUrl = $signedUrl.'&extra=changed';
        $tamperedPath = parse_url($tamperedUrl, PHP_URL_PATH).'?'.parse_url($tamperedUrl, PHP_URL_QUERY);
        $this->getJson($tamperedPath)->assertForbidden();
    }

    public function test_preview_button_guides_admin_to_settings_when_not_configured(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $post = Post::create([
            'title' => '設定前の記事',
            'slug' => 'before-settings',
            'body_markdown' => '本文',
            'status' => 'draft',
            'author_id' => $admin->id,
        ]);

        $this->actingAs($admin)->get("/admin/posts/{$post->id}/edit")
            ->assertOk()
            ->assertSee('プレビューURLを設定');

        $this->actingAs($admin)->get(route('admin.preview', $post))
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHas('warning');
    }
}

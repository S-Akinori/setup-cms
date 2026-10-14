<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportWordPressCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_wordpress_posts_as_markdown(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@example.test']);

        $this->artisan('cms:import-wordpress', [
            'file' => base_path('tests/Fixtures/wordpress-export.xml'),
            '--author-email' => $admin->email,
        ])->assertSuccessful();

        $post = Post::with(['category', 'tags', 'revisions'])->sole();

        $this->assertSame('first-post', $post->slug);
        $this->assertSame('最初の記事', $post->title);
        $this->assertSame('published', $post->status);
        $this->assertSame('ニュース', $post->category?->name);
        $this->assertSame(['WordPress'], $post->tags->pluck('name')->all());
        $this->assertSame('検索用の説明', $post->seo_description);
        $this->assertStringContainsString('## 見出し', $post->body_markdown);
        $this->assertStringContainsString('**本文**', $post->body_markdown);
        $this->assertStringNotContainsString('<script', $post->body_markdown);
        $this->assertCount(1, $post->revisions);
    }

    public function test_existing_slugs_are_skipped_by_default(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $arguments = [
            'file' => base_path('tests/Fixtures/wordpress-export.xml'),
            '--author-email' => $admin->email,
        ];

        $this->artisan('cms:import-wordpress', $arguments)->assertSuccessful();
        $this->artisan('cms:import-wordpress', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('posts', 1);
    }

    public function test_dry_run_does_not_write_to_the_database(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->artisan('cms:import-wordpress', [
            'file' => base_path('tests/Fixtures/wordpress-export.xml'),
            '--author-email' => $admin->email,
            '--dry-run' => true,
        ])->expectsOutput('事前確認のため、データは変更していません。')->assertSuccessful();

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('tags', 0);
    }
}

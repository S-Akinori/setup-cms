<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_markdown_post_with_taxonomies(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create(['name' => '技術', 'slug' => 'technology']);
        $response = $this->actingAs($admin)->post('/admin/posts', [
            'title' => '安全な記事', 'slug' => 'safe-post', 'description' => '概要',
            'body_markdown' => '# Heading\n\nInline math: $x^2$', 'status' => 'published',
            'category_id' => $category->id, 'tags' => 'Laravel, Astro', 'noindex' => '0',
        ]);

        $post = Post::where('slug', 'safe-post')->firstOrFail();
        $response->assertRedirect(route('admin.posts.edit', $post));
        $this->assertSame(2, $post->tags()->count());
        $this->assertSame('技術', $post->category->name);
        $this->assertSame('post', $post->content_type);
        $this->assertDatabaseHas('post_revisions', ['post_id' => $post->id, 'version' => 1]);
        $this->assertDatabaseHas('build_jobs', ['reason' => 'post.published', 'status' => 'pending']);
    }

    public function test_raw_html_is_rejected_but_html_in_code_block_is_allowed(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $base = ['title' => 'HTML test', 'slug' => 'html-test', 'status' => 'draft'];

        $this->actingAs($admin)->post('/admin/posts', $base + ['body_markdown' => '<script>alert(1)</script>'])
            ->assertSessionHasErrors('body_markdown');

        $this->actingAs($admin)->post('/admin/posts', $base + ['body_markdown' => "```html\n<div>example</div>\n```"])
            ->assertSessionHasNoErrors();
    }

    public function test_editor_empty_line_placeholders_are_normalized_before_validation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post('/admin/posts', [
            'title' => 'Editor Markdown',
            'slug' => 'editor-markdown',
            'body_markdown' => "## Heading\n\n<br />\n\nBody\n\n```html\n<br />\n```",
            'status' => 'draft',
            'tags' => null,
        ]);

        $post = Post::where('slug', 'editor-markdown')->firstOrFail();
        $response->assertRedirect(route('admin.posts.edit', $post));
        $this->assertSame("## Heading\n\n\n\nBody\n\n```html\n<br />\n```", $post->body_markdown);
        $this->assertCount(0, $post->tags);
    }

    public function test_stale_edit_is_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $post = Post::create(['title' => 'Original', 'slug' => 'original', 'body_markdown' => 'Body', 'status' => 'draft', 'author_id' => $admin->id, 'lock_version' => 2]);

        $this->actingAs($admin)->put("/admin/posts/{$post->id}", [
            'title' => 'Overwrite', 'slug' => 'original', 'body_markdown' => 'Changed', 'status' => 'draft', 'lock_version' => 1,
        ])->assertSessionHasErrors('lock_version');

        $this->assertSame('Original', $post->fresh()->title);
    }
}

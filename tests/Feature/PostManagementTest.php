<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Media;
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
        $featuredImage = Media::create([
            'disk' => 'public', 'path' => '2026/10/featured.jpg', 'original_name' => 'featured.jpg',
            'mime_type' => 'image/jpeg', 'size' => 2048, 'width' => 1200, 'height' => 630,
            'alt_text' => '記事のアイキャッチ', 'sha256' => str_repeat('f', 64), 'uploaded_by' => $admin->id,
        ]);
        $response = $this->actingAs($admin)->post('/admin/posts', [
            'title' => '安全な記事', 'slug' => 'safe-post', 'description' => '概要',
            'body_markdown' => '# Heading\n\nInline math: $x^2$', 'status' => 'published',
            'category_id' => $category->id, 'tags' => 'Laravel, Astro', 'noindex' => '0',
            'featured_image_id' => $featuredImage->id,
        ]);

        $post = Post::where('slug', 'safe-post')->firstOrFail();
        $response->assertRedirect(route('admin.posts.edit', $post));
        $this->assertSame(2, $post->tags()->count());
        $this->assertSame('技術', $post->category->name);
        $this->assertSame($featuredImage->id, $post->featured_image_id);
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

    public function test_admin_can_filter_articles_by_title_and_category(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $technology = Category::create(['name' => '技術', 'slug' => 'technology']);
        $news = Category::create(['name' => 'ニュース', 'slug' => 'news']);

        Post::create(['title' => 'Laravel入門', 'slug' => 'laravel-guide', 'body_markdown' => '本文', 'status' => 'draft', 'author_id' => $admin->id, 'category_id' => $technology->id]);
        Post::create(['title' => 'Laravelイベント', 'slug' => 'laravel-event', 'body_markdown' => '本文', 'status' => 'draft', 'author_id' => $admin->id, 'category_id' => $news->id]);
        Post::create(['title' => 'Astro入門', 'slug' => 'astro-guide', 'body_markdown' => '本文', 'status' => 'draft', 'author_id' => $admin->id, 'category_id' => $technology->id]);

        $this->actingAs($admin)
            ->get('/admin/posts?title=Laravel&category='.$technology->id)
            ->assertOk()
            ->assertSee('Laravel入門')
            ->assertDontSee('Laravelイベント')
            ->assertDontSee('Astro入門')
            ->assertSee('value="Laravel"', false);
    }

    public function test_admin_content_list_is_ordered_by_published_date_descending(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Post::create(['title' => '古い記事', 'slug' => 'older-post', 'body_markdown' => '本文', 'status' => 'published', 'published_at' => now()->subDays(2), 'author_id' => $admin->id]);
        Post::create(['title' => '新しい記事', 'slug' => 'newer-post', 'body_markdown' => '本文', 'status' => 'published', 'published_at' => now(), 'author_id' => $admin->id]);

        $this->actingAs($admin)
            ->get('/admin/posts')
            ->assertOk()
            ->assertSeeInOrder(['新しい記事', '古い記事']);
    }

    public function test_article_pagination_uses_the_admin_layout(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        foreach (range(1, 21) as $number) {
            Post::create([
                'title' => '記事 '.$number,
                'slug' => 'article-'.$number,
                'body_markdown' => '本文',
                'status' => 'draft',
                'author_id' => $admin->id,
            ]);
        }

        $this->actingAs($admin)->get('/admin/posts?title=記事')
            ->assertOk()
            ->assertSee('class="pagination"', false)
            ->assertSee('pagination-arrow', false)
            ->assertSee('?title=', false);
    }
}

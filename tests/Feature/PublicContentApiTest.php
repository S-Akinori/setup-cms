<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_published_posts_are_returned(): void
    {
        $author = User::factory()->create();
        Post::create(['slug' => 'visible', 'title' => 'Visible', 'body_markdown' => '# Hello $x$', 'status' => 'published', 'published_at' => now(), 'author_id' => $author->id]);
        Post::create(['slug' => 'draft', 'title' => 'Draft', 'body_markdown' => 'Secret', 'status' => 'draft', 'author_id' => $author->id]);

        $this->getJson('/api/v1/posts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'visible')->assertJsonPath('data.0.bodyMarkdown', '# Hello $x$');
        $this->getJson('/api/v1/posts/draft')->assertNotFound();
    }

    public function test_post_endpoint_returns_cache_validators(): void
    {
        $author = User::factory()->create();
        Post::create(['slug' => 'cached', 'title' => 'Cached', 'body_markdown' => 'Body', 'status' => 'published', 'published_at' => now(), 'author_id' => $author->id]);

        $response = $this->getJson('/api/v1/posts/cached')->assertOk();
        $response->assertHeader('ETag')->assertHeader('Last-Modified');
    }

    public function test_static_pages_have_separate_api_endpoints(): void
    {
        $author = User::factory()->create();
        $category = Category::create(['name' => '案内', 'slug' => 'guide']);
        Post::create(['content_type' => 'post', 'slug' => 'article', 'title' => 'Article', 'body_markdown' => 'Article body', 'status' => 'published', 'published_at' => now(), 'author_id' => $author->id]);
        Post::create(['content_type' => 'page', 'slug' => 'about', 'title' => 'About', 'body_markdown' => 'Page body', 'status' => 'published', 'published_at' => now(), 'author_id' => $author->id, 'category_id' => $category->id]);

        $this->getJson('/api/v1/posts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'article');
        $this->getJson('/api/v1/pages?category=guide')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'about');
        $this->getJson('/api/v1/pages/about')->assertOk()->assertJsonPath('data.type', 'page')->assertJsonPath('data.category.slug', 'guide');
        $this->getJson('/api/v1/posts/about')->assertNotFound();
    }
}

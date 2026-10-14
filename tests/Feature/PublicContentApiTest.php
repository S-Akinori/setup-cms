<?php

namespace Tests\Feature;

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
}

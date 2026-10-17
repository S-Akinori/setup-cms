<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_accepts_comments_as_pending_and_only_returns_approved_comments(): void
    {
        $post = $this->publishedPost();
        $approved = $post->comments()->create([
            'author_name' => '公開ユーザー',
            'author_email' => 'public@example.com',
            'body' => '公開コメント',
            'status' => 'approved',
        ]);
        $post->comments()->create([
            'author_name' => '未承認ユーザー',
            'author_email' => 'pending@example.com',
            'body' => '未承認コメント',
            'status' => 'pending',
        ]);

        $this->getJson('/api/v1/posts/article/comments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id)
            ->assertJsonMissing(['authorEmail' => 'public@example.com']);

        $this->postJson('/api/v1/posts/article/comments', [
            'authorName' => '新規ユーザー',
            'authorEmail' => 'new@example.com',
            'authorUrl' => 'https://example.com/profile',
            'body' => '新しいコメントです。',
            'parentId' => $approved->id,
            'company' => '',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('comments', [
            'post_id' => $post->id,
            'parent_id' => $approved->id,
            'author_email' => 'new@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_comments_can_be_disabled_per_content(): void
    {
        $post = $this->publishedPost(['comments_enabled' => false]);

        $this->postJson('/api/v1/posts/'.$post->slug.'/comments', [
            'authorName' => 'ユーザー',
            'authorEmail' => 'user@example.com',
            'body' => 'コメント',
        ])->assertForbidden();
    }

    public function test_admin_can_moderate_and_delete_comments(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $comment = $this->publishedPost()->comments()->create([
            'author_name' => '投稿者',
            'author_email' => 'author@example.com',
            'body' => '承認してください。',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->get('/admin/comments')
            ->assertOk()
            ->assertSeeText('承認してください。');

        $this->actingAs($admin)->patch('/admin/comments/'.$comment->id, ['status' => 'approved'])
            ->assertRedirect();
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'status' => 'approved']);

        $this->actingAs($admin)->delete('/admin/comments/'.$comment->id)->assertRedirect();
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    private function publishedPost(array $attributes = []): Post
    {
        return Post::create(array_merge([
            'content_type' => 'post',
            'slug' => 'article',
            'title' => '記事',
            'body_markdown' => '本文',
            'status' => 'published',
            'published_at' => now(),
            'author_id' => User::factory()->create()->id,
            'comments_enabled' => true,
        ], $attributes));
    }
}

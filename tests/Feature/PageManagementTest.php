<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_edit_a_static_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post('/admin/pages', [
            'title' => '会社概要',
            'slug' => 'about',
            'body_markdown' => '## 私たちについて',
            'status' => 'published',
        ]);

        $page = Post::where('slug', 'about')->firstOrFail();
        $response->assertRedirect(route('admin.pages.edit', $page));
        $this->assertSame('page', $page->content_type);
        $this->assertNull($page->category_id);
        $this->assertDatabaseHas('build_jobs', ['reason' => 'page.published']);

        $this->actingAs($admin)->put("/admin/pages/{$page->id}", [
            'title' => '私たちについて',
            'slug' => 'about',
            'body_markdown' => '更新本文',
            'status' => 'published',
            'lock_version' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('私たちについて', $page->fresh()->title);
    }

    public function test_article_route_cannot_edit_a_static_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $page = Post::create([
            'content_type' => 'page',
            'slug' => 'privacy',
            'title' => 'プライバシーポリシー',
            'body_markdown' => '本文',
            'status' => 'draft',
            'author_id' => $admin->id,
        ]);

        $this->actingAs($admin)->get("/admin/posts/{$page->id}/edit")->assertNotFound();
    }
}

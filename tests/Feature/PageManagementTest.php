<?php

namespace Tests\Feature;

use App\Models\Category;
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
        $category = Category::create(['name' => '企業情報', 'slug' => 'company']);

        $response = $this->actingAs($admin)->post('/admin/pages', [
            'title' => '会社概要',
            'slug' => 'about',
            'body_markdown' => '## 私たちについて',
            'status' => 'published',
            'category_id' => $category->id,
        ]);

        $page = Post::where('slug', 'about')->firstOrFail();
        $response->assertRedirect(route('admin.pages.edit', $page));
        $this->assertSame('page', $page->content_type);
        $this->assertSame($category->id, $page->category_id);
        $this->assertDatabaseHas('build_jobs', ['reason' => 'page.published']);

        $this->actingAs($admin)->put("/admin/pages/{$page->id}", [
            'title' => '私たちについて',
            'slug' => 'about',
            'body_markdown' => '更新本文',
            'status' => 'published',
            'category_id' => $category->id,
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

    public function test_admin_can_filter_static_pages_by_title_and_category(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $guide = Category::create(['name' => 'ガイド', 'slug' => 'guide']);
        $company = Category::create(['name' => '企業情報', 'slug' => 'company-info']);

        Post::create(['content_type' => 'page', 'title' => '利用ガイド', 'slug' => 'usage-guide', 'body_markdown' => '本文', 'status' => 'draft', 'author_id' => $admin->id, 'category_id' => $guide->id]);
        Post::create(['content_type' => 'page', 'title' => '会社ガイド', 'slug' => 'company-guide', 'body_markdown' => '本文', 'status' => 'draft', 'author_id' => $admin->id, 'category_id' => $company->id]);
        Post::create(['content_type' => 'page', 'title' => 'お問い合わせ', 'slug' => 'contact', 'body_markdown' => '本文', 'status' => 'draft', 'author_id' => $admin->id, 'category_id' => $guide->id]);

        $this->actingAs($admin)
            ->get('/admin/pages?title=ガイド&category='.$guide->id)
            ->assertOk()
            ->assertSee('利用ガイド')
            ->assertDontSee('会社ガイド')
            ->assertDontSee('お問い合わせ');
    }
}

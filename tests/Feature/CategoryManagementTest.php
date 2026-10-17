<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_categories_and_select_one_for_a_post(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/categories', [
            'name' => 'お知らせ',
            'slug' => 'news',
        ])->assertRedirect();

        $category = Category::where('slug', 'news')->firstOrFail();
        $this->actingAs($admin)->get('/admin/posts/create')
            ->assertOk()
            ->assertSee('<select name="category_id">', false)
            ->assertSee('お知らせ');

        $this->actingAs($admin)->put("/admin/categories/{$category->id}", [
            'name' => 'ニュース',
            'slug' => 'news',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'ニュース']);
    }

    public function test_deleting_category_makes_assigned_post_uncategorized(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create(['name' => '削除対象', 'slug' => 'delete-me']);
        $post = Post::create([
            'content_type' => 'post',
            'slug' => 'categorized-post',
            'title' => '記事',
            'body_markdown' => '本文',
            'status' => 'draft',
            'author_id' => $admin->id,
            'category_id' => $category->id,
        ]);

        $this->actingAs($admin)->delete("/admin/categories/{$category->id}")
            ->assertRedirect(route('admin.categories.index'));

        $this->assertNull($post->fresh()->category_id);
    }
}

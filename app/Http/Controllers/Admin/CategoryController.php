<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Audit;
use App\Services\BuildDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::withCount('posts')->orderBy('name')->paginate(30)->onEachSide(1);

        return view('admin.categories.index', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $category = Category::create($this->validated($request));
        Audit::record($request, 'category.created', $category);
        app(BuildDispatcher::class)->dispatch('category.created', ['category_id' => $category->id]);

        return redirect()->route('admin.categories.edit', $category)->with('success', 'カテゴリーを作成しました。');
    }

    public function edit(Category $category): View
    {
        $category->loadCount('posts');

        return view('admin.categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($this->validated($request, $category));
        Audit::record($request, 'category.updated', $category);
        app(BuildDispatcher::class)->dispatch('category.updated', ['category_id' => $category->id]);

        return back()->with('success', 'カテゴリーを更新しました。');
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $id = $category->id;
        $category->delete();
        Audit::record($request, 'category.deleted', $category);
        app(BuildDispatcher::class)->dispatch('category.deleted', ['category_id' => $id]);

        return redirect()->route('admin.categories.index')->with('success', 'カテゴリーを削除しました。カテゴリーを使用していた記事は「未分類」になります。');
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories')->ignore($category?->id),
            ],
        ]);
    }
}

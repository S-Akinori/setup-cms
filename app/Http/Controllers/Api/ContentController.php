<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;

class ContentController extends Controller
{
    public function posts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'updated_after' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:120'],
        ]);
        $posts = Post::articles()->published()->with(['author:id,name', 'category:id,name,slug', 'tags:id,name,slug', 'featuredImage'])
            ->when($validated['updated_after'] ?? null, fn ($q, $date) => $q->where('updated_at', '>', $date))
            ->when($validated['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($validated['tag'] ?? null, fn ($q, $slug) => $q->whereHas('tags', fn ($t) => $t->where('slug', $slug)))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['limit'] ?? 20);

        return response()->json($this->serializePaginator($posts))
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    public function post(string $slug, Request $request): JsonResponse
    {
        $post = Post::articles()->published()->where('slug', $slug)->with(['author:id,name', 'category:id,name,slug', 'tags:id,name,slug', 'featuredImage'])->firstOrFail();
        $etag = '"'.hash('sha256', $post->updated_at->toISOString().':'.$post->lock_version).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }

        return response()->json(['data' => $this->serialize($post)])
            ->header('ETag', $etag)->header('Last-Modified', $post->updated_at->toRfc7231String())
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    public function pages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'updated_after' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:120'],
        ]);
        $pages = Post::pages()->published()->with(['author:id,name', 'category:id,name,slug', 'featuredImage'])
            ->when($validated['updated_after'] ?? null, fn ($q, $date) => $q->where('updated_at', '>', $date))
            ->when($validated['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['limit'] ?? 20);

        return response()->json($this->serializePaginator($pages))
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    public function page(string $slug, Request $request): JsonResponse
    {
        $page = Post::pages()->published()->where('slug', $slug)->with(['author:id,name', 'category:id,name,slug', 'featuredImage'])->firstOrFail();
        $etag = '"'.hash('sha256', $page->updated_at->toISOString().':'.$page->lock_version).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }

        return response()->json(['data' => $this->serialize($page)])
            ->header('ETag', $etag)->header('Last-Modified', $page->updated_at->toRfc7231String())
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    public function manifest(): JsonResponse
    {
        return response()->json(['data' => Post::published()->orderByDesc('published_at')->orderByDesc('id')->get(['id', 'content_type', 'slug', 'updated_at', 'published_at'])])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function taxonomies(): JsonResponse
    {
        return response()->json(['data' => ['categories' => Category::orderBy('name')->get(['name', 'slug']), 'tags' => Tag::orderBy('name')->get(['name', 'slug'])]])
            ->header('Cache-Control', 'public, max-age=300');
    }

    private function serializePaginator(CursorPaginator $paginator): array
    {
        // Cursor values must be calculated while the collection still contains
        // Post models with every ordered column available.
        $nextCursor = $paginator->nextCursor()?->encode();
        $nextPageUrl = $paginator->nextPageUrl();
        $previousCursor = $paginator->previousCursor()?->encode();
        $previousPageUrl = $paginator->previousPageUrl();

        return [
            'data' => $paginator->getCollection()
                ->map(fn (Post $post) => $this->serialize($post))
                ->values(),
            'path' => $paginator->path(),
            'per_page' => $paginator->perPage(),
            'next_cursor' => $nextCursor,
            'next_page_url' => $nextPageUrl,
            'prev_cursor' => $previousCursor,
            'prev_page_url' => $previousPageUrl,
        ];
    }

    private function serialize(Post $post): array
    {
        return [
            'id' => $post->id, 'slug' => $post->slug, 'title' => $post->title,
            'type' => $post->content_type,
            'commentsEnabled' => $post->comments_enabled,
            'description' => $post->description, 'bodyMarkdown' => $post->body_markdown,
            'publishedAt' => $post->published_at?->toISOString(), 'updatedAt' => $post->updated_at->toISOString(),
            'author' => $post->author?->only(['name']),
            'category' => $post->category?->only(['name', 'slug']),
            'tags' => $post->content_type === 'post' ? $post->tags->map->only(['name', 'slug'])->values() : [],
            'featuredImage' => $post->featuredImage ? ['url' => asset('storage/'.$post->featuredImage->path), 'alt' => $post->featuredImage->alt_text, 'width' => $post->featuredImage->width, 'height' => $post->featuredImage->height] : null,
            'seo' => ['title' => $post->seo_title, 'description' => $post->seo_description, 'canonicalUrl' => $post->canonical_url, 'noindex' => $post->noindex],
        ];
    }
}

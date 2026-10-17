<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommentController extends Controller
{
    public function postIndex(string $slug, Request $request): JsonResponse
    {
        return $this->index($this->content($slug, 'post'), $request);
    }

    public function pageIndex(string $slug, Request $request): JsonResponse
    {
        return $this->index($this->content($slug, 'page'), $request);
    }

    public function postStore(string $slug, Request $request): JsonResponse
    {
        return $this->store($this->content($slug, 'post'), $request);
    }

    public function pageStore(string $slug, Request $request): JsonResponse
    {
        return $this->store($this->content($slug, 'page'), $request);
    }

    private function index(Post $post, Request $request): JsonResponse
    {
        $validated = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $comments = $post->comments()->approved()->oldest()->cursorPaginate($validated['limit'] ?? 50);

        return response()->json($comments->through(fn (Comment $comment) => $this->serialize($comment)))
            ->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=120');
    }

    private function store(Post $post, Request $request): JsonResponse
    {
        abort_unless($post->comments_enabled, 403, 'このコンテンツではコメントを受け付けていません。');

        $data = $request->validate([
            'authorName' => ['required', 'string', 'max:100'],
            'authorEmail' => ['required', 'email:rfc', 'max:254'],
            'authorUrl' => ['nullable', 'url:http,https', 'max:2048'],
            'body' => ['required', 'string', 'max:5000'],
            'parentId' => [
                'nullable',
                'string',
                Rule::exists('comments', 'id')->where(fn ($query) => $query->where('post_id', $post->id)->where('status', 'approved')),
            ],
            'company' => ['nullable', 'max:0'],
        ]);

        $comment = $post->comments()->create([
            'parent_id' => $data['parentId'] ?? null,
            'author_name' => trim($data['authorName']),
            'author_email' => mb_strtolower(trim($data['authorEmail'])),
            'author_url' => $data['authorUrl'] ?? null,
            'body' => trim($data['body']),
            'status' => 'pending',
            'author_ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), (string) config('app.key')) : null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
        ]);

        return response()->json([
            'message' => 'コメントを受け付けました。承認後に公開されます。',
            'data' => ['id' => $comment->id, 'status' => $comment->status],
        ], 202)->header('Cache-Control', 'no-store');
    }

    private function content(string $slug, string $type): Post
    {
        return Post::published()->where('content_type', $type)->where('slug', $slug)->firstOrFail();
    }

    private function serialize(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'parentId' => $comment->parent_id,
            'authorName' => $comment->author_name,
            'authorUrl' => $comment->author_url,
            'body' => $comment->body,
            'createdAt' => $comment->created_at->toISOString(),
        ];
    }
}

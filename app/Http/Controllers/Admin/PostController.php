<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Tag;
use App\Rules\NoRawHtml;
use App\Services\Audit;
use App\Services\BuildDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PostController extends Controller
{
    public function index(Request $request): View
    {
        $contentType = $this->contentType($request);
        $posts = Post::where('content_type', $contentType)->with(['category', 'author'])->latest('updated_at')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->paginate(20)->withQueryString();

        return view('admin.posts.index', compact('posts', 'contentType'));
    }

    public function create(Request $request): View
    {
        return view('admin.posts.create', $this->formData($this->contentType($request)));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $contentType = $this->contentType($request);
        $post = DB::transaction(function () use ($request, $data, $contentType) {
            $data['content_type'] = $contentType;
            $data['author_id'] = $request->user()->id;
            $data['category_id'] = $contentType === 'post' ? ($data['category_id'] ?? null) : null;
            unset($data['tags'], $data['lock_version']);
            $this->normalizeDates($data);
            $post = Post::create($data);
            $this->syncTags($post, $contentType === 'post' ? $request->input('tags', '') : '');
            $this->revision($post, $request->user()->id);
            Audit::record($request, $contentType.'.created', $post, ['status' => $post->status]);

            return $post;
        });

        if ($post->status === 'published') {
            app(BuildDispatcher::class)->dispatch($contentType.'.published', ['post_id' => $post->id]);
        }

        return redirect()->route($this->routeName($contentType, 'edit'), $post)
            ->with('success', ($contentType === 'page' ? '固定ページ' : '記事').'を保存しました。');
    }

    public function edit(Request $request, Post $post): View
    {
        $contentType = $this->contentType($request);
        $this->ensureContentType($post, $contentType);
        $post->load(['tags', 'category', 'revisions' => fn ($q) => $q->latest()->limit(20)]);

        return view('admin.posts.edit', array_merge($this->formData($contentType), compact('post')));
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $contentType = $this->contentType($request);
        $this->ensureContentType($post, $contentType);
        $data = $this->validated($request, $post);
        $expectedVersion = (int) $data['lock_version'];
        $wasPublished = $post->status === 'published';

        DB::transaction(function () use ($request, $post, $data, $expectedVersion, $contentType) {
            if ((int) $post->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['lock_version' => '別の操作で記事が更新されました。ページを再読み込みしてください。']);
            }
            $this->revision($post, $request->user()->id);
            $data['category_id'] = $contentType === 'post' ? ($data['category_id'] ?? null) : null;
            unset($data['tags'], $data['lock_version']);
            $data['lock_version'] = $expectedVersion + 1;
            $this->normalizeDates($data);
            $updated = Post::whereKey($post->id)->where('lock_version', $expectedVersion)->update($data);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['lock_version' => '別の操作で記事が更新されました。']);
            }
            $post->refresh();
            $this->syncTags($post, $contentType === 'post' ? $request->input('tags', '') : '');
            Audit::record($request, $contentType.'.updated', $post, ['status' => $post->status]);
        });

        if ($post->status === 'published' || $wasPublished) {
            app(BuildDispatcher::class)->dispatch($contentType.'.updated', ['post_id' => $post->id]);
        }

        return back()->with('success', ($contentType === 'page' ? '固定ページ' : '記事').'を更新しました。');
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $contentType = $this->contentType($request);
        $this->ensureContentType($post, $contentType);
        $wasPublished = $post->status === 'published';
        $post->delete();
        Audit::record($request, $contentType.'.deleted', $post);
        if ($wasPublished) {
            app(BuildDispatcher::class)->dispatch($contentType.'.deleted', ['post_id' => $post->id]);
        }

        return redirect()->route($this->routeName($contentType, 'index'))
            ->with('success', ($contentType === 'page' ? '固定ページ' : '記事').'をゴミ箱へ移動しました。');
    }

    public function restoreRevision(Request $request, Post $post, PostRevision $revision): RedirectResponse
    {
        $contentType = $this->contentType($request);
        $this->ensureContentType($post, $contentType);
        abort_unless($revision->post_id === $post->id, 404);
        $snapshot = $revision->snapshot;
        DB::transaction(function () use ($request, $post, $snapshot) {
            $this->revision($post, $request->user()->id);
            $post->fill(collect($snapshot)->only(['slug', 'title', 'description', 'body_markdown', 'status', 'published_at', 'scheduled_at', 'category_id', 'featured_image_id', 'seo_title', 'seo_description', 'canonical_url', 'noindex', 'comments_enabled'])->all());
            $post->lock_version++;
            $post->save();
            $post->tags()->sync($snapshot['tag_ids'] ?? []);
            Audit::record($request, $post->content_type.'.revision_restored', $post, ['version' => $revision->version]);
        });
        if ($post->status === 'published') {
            app(BuildDispatcher::class)->dispatch($contentType.'.restored', ['post_id' => $post->id]);
        }

        return back()->with('success', '過去の版を復元しました。');
    }

    private function validated(Request $request, ?Post $post = null): array
    {
        $markdown = $request->input('body_markdown');
        if (is_string($markdown)) {
            $request->merge(['body_markdown' => $this->normalizeEditorMarkdown($markdown)]);
        }

        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('posts')->ignore($post?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'body_markdown' => ['required', 'string', 'max:1000000', new NoRawHtml],
            'status' => ['required', Rule::in(['draft', 'scheduled', 'published', 'archived'])],
            'published_at' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date', 'required_if:status,scheduled'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'tags' => ['nullable', 'string', 'max:1000'],
            'featured_image_id' => ['nullable', Rule::exists('media', 'id')],
            'seo_title' => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'canonical_url' => ['nullable', 'url:http,https', 'max:2048'],
            'noindex' => ['nullable', 'boolean'],
            'comments_enabled' => ['nullable', 'boolean'],
            'lock_version' => [$post ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);
    }

    private function normalizeEditorMarkdown(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [$markdown];
        $fenceCharacter = null;
        $fenceLength = 0;

        foreach ($lines as &$line) {
            if (preg_match('/^[ \t]{0,3}(`{3,}|~{3,})/', $line, $match) === 1) {
                $character = $match[1][0];
                $length = strlen($match[1]);

                if ($fenceCharacter === null) {
                    $fenceCharacter = $character;
                    $fenceLength = $length;
                } elseif ($character === $fenceCharacter
                    && $length >= $fenceLength
                    && preg_match('/^[ \t]{0,3}(`{3,}|~{3,})[ \t]*$/', $line) === 1) {
                    $fenceCharacter = null;
                    $fenceLength = 0;
                }

                continue;
            }

            if ($fenceCharacter === null && preg_match('/^[ \t]{0,3}<br\s*\/?>[ \t]*$/i', $line) === 1) {
                $line = '';
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    private function formData(string $contentType): array
    {
        return [
            'contentType' => $contentType,
            'categories' => Category::orderBy('name')->get(),
            'mediaItems' => Media::latest()->limit(100)->get(),
        ];
    }

    private function syncTags(Post $post, ?string $input): void
    {
        $ids = collect(explode(',', $input ?? ''))->map(fn ($name) => trim($name))->filter()->unique()
            ->take(30)->map(function ($name) {
                $slug = Str::slug($name) ?: Str::lower(Str::random(8));

                return Tag::firstOrCreate(['slug' => $slug], ['name' => $name])->id;
            });
        $post->tags()->sync($ids);
    }

    private function normalizeDates(array &$data): void
    {
        $data['noindex'] = (bool) ($data['noindex'] ?? false);
        $data['comments_enabled'] = (bool) ($data['comments_enabled'] ?? false);
        if ($data['status'] === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
        if ($data['status'] !== 'scheduled') {
            $data['scheduled_at'] = null;
        }
    }

    private function revision(Post $post, int $userId): void
    {
        $version = ((int) $post->revisions()->max('version')) + 1;
        PostRevision::create([
            'post_id' => $post->id,
            'user_id' => $userId,
            'version' => $version,
            'snapshot' => array_merge($post->only(['slug', 'title', 'description', 'body_markdown', 'status', 'published_at', 'scheduled_at', 'category_id', 'featured_image_id', 'seo_title', 'seo_description', 'canonical_url', 'noindex', 'comments_enabled']), ['tag_ids' => $post->tags()->pluck('tags.id')->all()]),
        ]);
    }

    private function contentType(Request $request): string
    {
        return $request->routeIs('admin.pages.*') ? 'page' : 'post';
    }

    private function routeName(string $contentType, string $action): string
    {
        return 'admin.'.($contentType === 'page' ? 'pages' : 'posts').'.'.$action;
    }

    private function ensureContentType(Post $post, string $contentType): void
    {
        abort_unless($post->content_type === $contentType, 404);
    }
}

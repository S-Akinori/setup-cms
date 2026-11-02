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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class PostController extends Controller
{
    public function index(Request $request): View
    {
        $contentType = $this->contentType($request);
        $title = trim((string) $request->query('title', ''));
        $categoryId = $request->integer('category');
        $posts = Post::where('content_type', $contentType)->with(['category', 'author'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($title !== '', fn ($query) => $query->where('title', 'like', '%'.$title.'%'))
            ->when($categoryId > 0, fn ($query) => $query->where('category_id', $categoryId))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(20)->withQueryString()->onEachSide(1);
        $categories = Category::orderBy('name')->get(['id', 'name']);

        return view('admin.posts.index', compact('posts', 'contentType', 'categories'));
    }

    public function create(Request $request): View
    {
        return view('admin.posts.create', $this->formData($this->contentType($request)));
    }

    public function exportMarkdown(Request $request): StreamedResponse|RedirectResponse
    {
        $contentType = $this->contentType($request);
        $validated = $request->validate([
            'post_ids' => ['required', 'array', 'min:1', 'max:100'],
            'post_ids.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('posts', 'id')
                    ->where('content_type', $contentType)
                    ->whereNull('deleted_at'),
            ],
        ], [
            'post_ids.required' => '出力する'.($contentType === 'page' ? '固定ページ' : '記事').'を選択してください。',
            'post_ids.array' => '選択内容が正しくありません。',
            'post_ids.min' => '出力する'.($contentType === 'page' ? '固定ページ' : '記事').'を選択してください。',
            'post_ids.max' => '一度に出力できるのは100件までです。',
            'post_ids.*.exists' => '選択した項目に出力できないデータが含まれています。',
            'post_ids.*.distinct' => '同じ項目が複数回選択されています。',
        ]);

        if (! class_exists(ZipArchive::class)) {
            return back()->withErrors(['post_ids' => 'Markdownの一括出力にはPHPのZIP拡張が必要です。']);
        }

        $ids = collect($validated['post_ids']);
        $posts = Post::query()
            ->where('content_type', $contentType)
            ->whereIn('id', $ids)
            ->get(['id', 'slug', 'body_markdown'])
            ->keyBy('id');

        $directory = storage_path('app/private/exports');
        File::ensureDirectoryExists($directory);
        $temporaryPath = tempnam($directory, 'markdown-');
        if ($temporaryPath === false) {
            return back()->withErrors(['post_ids' => '一時ファイルを作成できませんでした。サーバーの書き込み権限を確認してください。']);
        }

        $zip = new ZipArchive;
        if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            File::delete($temporaryPath);

            return back()->withErrors(['post_ids' => 'Markdown ZIPを作成できませんでした。']);
        }

        foreach ($ids as $id) {
            $post = $posts->get($id);
            if (! $post) {
                continue;
            }

            $markdown = rtrim(str_replace(["\r\n", "\r"], "\n", $post->body_markdown))."\n";
            $zip->addFromString($post->slug.'.md', $markdown);
        }
        $zip->close();

        $prefix = $contentType === 'page' ? 'pages' : 'posts';
        $downloadName = $prefix.'-markdown-'.now()->format('Ymd-His').'.zip';

        return response()->streamDownload(function () use ($temporaryPath) {
            try {
                $handle = fopen($temporaryPath, 'rb');
                if ($handle !== false) {
                    try {
                        fpassthru($handle);
                    } finally {
                        fclose($handle);
                    }
                }
            } finally {
                File::delete($temporaryPath);
            }
        }, $downloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $contentType = $this->contentType($request);
        $post = DB::transaction(function () use ($request, $data, $contentType) {
            $data['content_type'] = $contentType;
            $data['author_id'] = $request->user()->id;
            $data['category_id'] = $data['category_id'] ?? null;
            $this->applyAccessPassword($data);
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
        $post->load(['tags', 'category', 'featuredImage', 'revisions' => fn ($q) => $q->latest()->limit(20)]);

        return view('admin.posts.edit', array_merge($this->formData($contentType), compact('post')));
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $contentType = $this->contentType($request);
        $this->ensureContentType($post, $contentType);
        $data = $this->validated($request, $post);
        $expectedVersion = (int) $data['lock_version'];
        $wasPublished = $post->status === 'published';
        $this->applyAccessPassword($data, true);

        DB::transaction(function () use ($request, $post, $data, $expectedVersion, $contentType) {
            if ((int) $post->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['lock_version' => '別の操作で記事が更新されました。ページを再読み込みしてください。']);
            }
            $this->revision($post, $request->user()->id);
            $data['category_id'] = $data['category_id'] ?? null;
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
            'access_password' => $this->contentType($request) === 'post'
                ? ['exclude_if:remove_access_password,1', 'nullable', 'string', 'min:8', 'max:255']
                : ['prohibited'],
            'remove_access_password' => $this->contentType($request) === 'post'
                ? ['nullable', 'boolean']
                : ['prohibited'],
            'lock_version' => [$post ? 'required' : 'nullable', 'integer', 'min:1'],
        ], [
            'access_password.min' => '限定公開パスワードは8文字以上で入力してください。',
            'access_password.max' => '限定公開パスワードは255文字以内で入力してください。',
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

    private function applyAccessPassword(array &$data, bool $updating = false): void
    {
        $remove = (bool) ($data['remove_access_password'] ?? false);
        $password = $data['access_password'] ?? null;
        unset($data['access_password'], $data['remove_access_password']);

        if ($remove) {
            $data['access_password'] = null;
        } elseif (is_string($password) && $password !== '') {
            $data['access_password'] = Hash::make($password);
        } elseif (! $updating) {
            $data['access_password'] = null;
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

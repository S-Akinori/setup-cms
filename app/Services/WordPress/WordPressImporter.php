<?php

namespace App\Services\WordPress;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class WordPressImporter
{
    private const REPORT_ITEM_LIMIT = 500;

    public function __construct(
        private readonly WxrReader $reader,
        private readonly HtmlToMarkdown $converter,
        private readonly WordPressImageImporter $imageImporter,
    ) {}

    /**
     * @return array{created: int, updated: int, skipped: int, ignored: int, images: int, missing_images: int, comments_imported: int, comments_skipped: int, warnings: list<string>, items: list<array{type: string, title: string, slug: string, status: string, action: string, reason: string|null}>, items_total: int, items_truncated: bool, type_counts: array<string, array<string, int>>}
     */
    public function import(
        string $path,
        User $author,
        ?string $uploadsDirectory = null,
        bool $updateExisting = false,
        bool $dryRun = false,
    ): array {
        $items = iterator_to_array($this->reader->items($path), false);
        $attachments = [];
        foreach ($items as $item) {
            if ($item['type'] === 'attachment' && $item['id'] !== '' && $item['attachment_url'] !== '') {
                $attachments[$item['id']] = [
                    'url' => $item['attachment_url'],
                    'alt' => $item['meta']['_wp_attachment_image_alt'] ?? $item['title'],
                ];
            }
        }

        $report = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'ignored' => 0,
            'images' => 0,
            'missing_images' => 0,
            'comments_imported' => 0,
            'comments_skipped' => 0,
            'warnings' => [],
            'items' => [],
            'items_total' => 0,
            'items_truncated' => false,
            'type_counts' => [],
        ];
        $resolvedImages = [];
        $seenSlugs = [];

        foreach ($items as $item) {
            if (! in_array($item['type'], ['post', 'page'], true)) {
                $report['ignored']++;
                $ignoredType = $item['type'] !== '' ? $item['type'] : 'unknown';
                $this->appendReportItem(
                    $report,
                    $item,
                    $this->slug($item),
                    $ignoredType,
                    'ignored',
                    $this->ignoredReason($ignoredType),
                );

                continue;
            }

            $slug = $this->slug($item);
            $contentType = $item['type'] === 'page' ? 'page' : 'post';
            if (isset($seenSlugs[$slug])) {
                $report['skipped']++;
                $report['warnings'][] = "WXR内でslugが重複しているためスキップ: {$slug}";
                $this->appendReportItem($report, $item, $slug, $contentType, 'skipped', 'WXR内でスラッグが重複');

                continue;
            }
            $seenSlugs[$slug] = true;

            $existing = Post::withTrashed()->where('slug', $slug)->first();
            if ($existing && ! $updateExisting) {
                $report['skipped']++;
                $this->appendReportItem($report, $item, $slug, $contentType, 'skipped', '同じスラッグが登録済み');
                $commentResult = $dryRun
                    ? $this->previewComments($item['comments'], $existing)
                    : DB::transaction(fn () => $this->importComments($existing, $item['comments'], false));
                $report['comments_imported'] += $commentResult['imported'];
                $report['comments_skipped'] += $commentResult['skipped'];

                continue;
            }

            if ($dryRun) {
                $action = $existing ? 'updated' : 'created';
                $report[$action]++;
                $this->appendReportItem($report, $item, $slug, $contentType, $action);
                $commentResult = $this->previewComments($item['comments'], $existing, $updateExisting);
                $report['comments_imported'] += $commentResult['imported'];
                $report['comments_skipped'] += $commentResult['skipped'];

                continue;
            }

            $commentResult = ['imported' => 0, 'skipped' => 0];
            try {
                DB::transaction(function () use (
                    $item,
                    $slug,
                    $contentType,
                    $existing,
                    $author,
                    $uploadsDirectory,
                    $attachments,
                    &$resolvedImages,
                    &$report,
                    &$commentResult,
                    $updateExisting,
                ): void {
                    if ($existing) {
                        $this->revision($existing, $author->id);
                    }

                    $imageResolver = function (string $url, string $alt) use (
                        $uploadsDirectory,
                        $author,
                        &$resolvedImages,
                        &$report,
                    ): ?string {
                        if (! $uploadsDirectory) {
                            return null;
                        }
                        if (isset($resolvedImages[$url])) {
                            return $resolvedImages[$url]['url'];
                        }

                        $media = $this->imageImporter->import($url, $uploadsDirectory, $author->id, $alt);
                        if (! $media) {
                            $report['missing_images']++;

                            return null;
                        }

                        $report['images']++;
                        $resolvedImages[$url] = [
                            'id' => $media->id,
                            'url' => asset('storage/'.$media->path),
                        ];

                        return $resolvedImages[$url]['url'];
                    };

                    $body = $this->converter->convert($item['content'], $imageResolver);
                    [$status, $publishedAt, $scheduledAt] = $this->statusAndDates($item['status'], $item['date']);
                    $categoryId = $contentType === 'post' ? $this->categoryId($item['categories']) : null;
                    $tagIds = $contentType === 'post' ? $this->tagIds($item['categories']) : [];
                    $featuredImageId = $existing?->featured_image_id;
                    $thumbnailId = $item['meta']['_thumbnail_id'] ?? null;
                    if ($uploadsDirectory && is_string($thumbnailId) && isset($attachments[$thumbnailId])) {
                        $attachment = $attachments[$thumbnailId];
                        $imageResolver($attachment['url'], $attachment['alt']);
                        $featuredImageId = $resolvedImages[$attachment['url']]['id'] ?? null;
                    }

                    $post = $existing ?? new Post;
                    if ($post->trashed()) {
                        $post->restore();
                    }
                    $post->fill([
                        'content_type' => $contentType,
                        'slug' => $slug,
                        'title' => mb_substr($item['title'] !== '' ? $item['title'] : '無題', 0, 200),
                        'description' => $this->description($item['excerpt']),
                        'body_markdown' => $body,
                        'status' => $status,
                        'published_at' => $publishedAt,
                        'scheduled_at' => $scheduledAt,
                        'author_id' => $author->id,
                        'category_id' => $categoryId,
                        'featured_image_id' => $featuredImageId,
                        'seo_title' => $this->nullableLimit($item['meta']['_yoast_wpseo_title'] ?? null, 200),
                        'seo_description' => $this->nullableLimit($item['meta']['_yoast_wpseo_metadesc'] ?? null, 320),
                        'canonical_url' => $this->validUrl($item['meta']['_yoast_wpseo_canonical'] ?? null),
                        'noindex' => in_array($item['meta']['_yoast_wpseo_meta-robots-noindex'] ?? null, ['1', 'noindex'], true),
                        'comments_enabled' => ($item['comment_status'] ?? '') !== 'closed',
                        'lock_version' => $existing ? ((int) $existing->lock_version + 1) : 1,
                    ]);
                    $post->save();
                    $post->tags()->sync($tagIds);
                    $commentResult = $this->importComments($post, $item['comments'], $updateExisting);

                    if (! $existing) {
                        $this->revision($post, $author->id);
                    }
                });

                $report[$existing ? 'updated' : 'created']++;
                $report['comments_imported'] += $commentResult['imported'];
                $report['comments_skipped'] += $commentResult['skipped'];
                $this->appendReportItem($report, $item, $slug, $contentType, $existing ? 'updated' : 'created');
            } catch (Throwable $error) {
                $report['skipped']++;
                $report['warnings'][] = "{$slug}: {$error->getMessage()}";
                $this->appendReportItem($report, $item, $slug, $contentType, 'skipped', $error->getMessage());
            }
        }

        return $report;
    }

    /** @param array<string, mixed> $item */
    private function slug(array $item): string
    {
        $decoded = rawurldecode((string) $item['slug']);
        $slug = Str::slug($decoded);

        return mb_substr($slug !== '' ? $slug : 'wordpress-'.($item['id'] ?: substr(sha1($item['title']), 0, 12)), 0, 180);
    }

    /**
     * @return array{string, ?CarbonImmutable, ?CarbonImmutable}
     */
    private function statusAndDates(string $wordpressStatus, string $date): array
    {
        $parsedDate = null;
        if ($date !== '' && $date !== '0000-00-00 00:00:00') {
            try {
                $parsedDate = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date, 'UTC');
            } catch (Throwable) {
                $parsedDate = null;
            }
        }

        return match ($wordpressStatus) {
            'publish' => ['published', $parsedDate ?? now()->toImmutable(), null],
            'future' => ['scheduled', null, $parsedDate ?? now()->toImmutable()],
            'trash' => ['archived', null, null],
            default => ['draft', null, null],
        };
    }

    /** @param list<array{domain: string, slug: string, name: string}> $terms */
    private function categoryId(array $terms): ?int
    {
        $term = collect($terms)->firstWhere('domain', 'category');
        if (! $term || trim($term['name']) === '') {
            return null;
        }

        $slug = $this->termSlug($term['slug'], $term['name'], 'category');

        return Category::firstOrCreate(['slug' => $slug], ['name' => mb_substr($term['name'], 0, 100)])->id;
    }

    /** @param list<array{domain: string, slug: string, name: string}> $terms */
    private function tagIds(array $terms): array
    {
        return collect($terms)
            ->where('domain', 'post_tag')
            ->filter(fn ($term) => trim($term['name']) !== '')
            ->unique('slug')
            ->take(30)
            ->map(function ($term) {
                $slug = $this->termSlug($term['slug'], $term['name'], 'tag');

                return Tag::firstOrCreate(['slug' => $slug], ['name' => mb_substr($term['name'], 0, 100)])->id;
            })
            ->values()
            ->all();
    }

    private function termSlug(string $slug, string $name, string $prefix): string
    {
        $normalized = Str::slug(rawurldecode($slug)) ?: Str::slug($name);

        return mb_substr($normalized ?: $prefix.'-'.substr(sha1($name), 0, 12), 0, 120);
    }

    private function description(string $excerpt): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($excerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $text === '' ? null : Str::limit($text, 500, '');
    }

    private function nullableLimit(mixed $value, int $length): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $length);
    }

    private function validUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        return mb_substr($value, 0, 2048);
    }

    private function revision(Post $post, int $userId): void
    {
        $version = ((int) $post->revisions()->max('version')) + 1;
        PostRevision::create([
            'post_id' => $post->id,
            'user_id' => $userId,
            'version' => $version,
            'snapshot' => array_merge(
                $post->only(['slug', 'title', 'description', 'body_markdown', 'status', 'published_at', 'scheduled_at', 'category_id', 'featured_image_id', 'seo_title', 'seo_description', 'canonical_url', 'noindex', 'comments_enabled']),
                ['tag_ids' => $post->tags()->pluck('tags.id')->all()]
            ),
        ]);
    }

    /** @param array<string, mixed> $report @param array<string, mixed> $item */
    private function appendReportItem(array &$report, array $item, string $slug, string $contentType, string $action, ?string $reason = null): void
    {
        $report['items_total']++;
        $report['type_counts'][$action][$contentType] = ($report['type_counts'][$action][$contentType] ?? 0) + 1;
        if (count($report['items']) >= self::REPORT_ITEM_LIMIT) {
            $report['items_truncated'] = true;

            return;
        }

        $report['items'][] = [
            'type' => $contentType,
            'title' => mb_substr($item['title'] !== '' ? $item['title'] : '無題', 0, 200),
            'slug' => $slug,
            'status' => match ($item['status']) {
                'publish' => 'published',
                'future' => 'scheduled',
                'trash' => 'archived',
                default => 'draft',
            },
            'action' => $action,
            'reason' => $reason ? mb_substr($reason, 0, 300) : null,
        ];
    }

    private function ignoredReason(string $type): string
    {
        return match ($type) {
            'attachment' => '添付ファイル情報（画像ファイルはCLI移行を使用）',
            'nav_menu_item' => 'WordPressのメニュー項目',
            'revision' => 'WordPressのリビジョン',
            'custom_css', 'customize_changeset' => 'WordPressテーマの設定データ',
            'unknown' => '投稿タイプを判別できないデータ',
            default => "未対応の投稿タイプ: {$type}",
        };
    }

    /**
     * @param  list<array<string, string>>  $comments
     * @return array{imported: int, skipped: int}
     */
    private function previewComments(array $comments, ?Post $post = null, bool $updateExisting = false): array
    {
        $result = ['imported' => 0, 'skipped' => 0];

        foreach ($comments as $comment) {
            if (! $this->isImportableComment($comment)) {
                $result['skipped']++;

                continue;
            }

            $sourceId = $this->commentSourceId($comment);
            $exists = $post && Comment::where('post_id', $post->id)->where('wordpress_id', $sourceId)->exists();
            $result[$exists && ! $updateExisting ? 'skipped' : 'imported']++;
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $comments
     * @return array{imported: int, skipped: int}
     */
    private function importComments(Post $post, array $comments, bool $updateExisting): array
    {
        $result = ['imported' => 0, 'skipped' => 0];
        $idMap = [];
        $eligible = [];

        foreach ($comments as $commentData) {
            if (! $this->isImportableComment($commentData)) {
                $result['skipped']++;

                continue;
            }

            $sourceId = $this->commentSourceId($commentData);
            $comment = Comment::where('post_id', $post->id)->where('wordpress_id', $sourceId)->first();
            if ($comment && ! $updateExisting) {
                $idMap[$commentData['id']] = $comment->id;
                $result['skipped']++;

                continue;
            }

            $values = [
                'author_name' => mb_substr(trim($commentData['author_name']) ?: '匿名', 0, 100),
                'author_email' => filter_var($commentData['author_email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower($commentData['author_email']) : null,
                'author_url' => filter_var($commentData['author_url'], FILTER_VALIDATE_URL) ? mb_substr($commentData['author_url'], 0, 2048) : null,
                'body' => $this->plainComment($commentData['content']),
                'status' => $this->commentStatus($commentData['approved']),
                'author_ip_hash' => $commentData['author_ip'] !== '' ? hash_hmac('sha256', $commentData['author_ip'], (string) config('app.key')) : null,
            ];
            $createdAt = $this->commentDate($commentData['date']);
            if ($createdAt) {
                $values['created_at'] = $createdAt;
            }

            if ($comment) {
                $comment->update($values);
            } else {
                $comment = $post->comments()->create(array_merge($values, ['wordpress_id' => $sourceId]));
            }

            $idMap[$commentData['id']] = $comment->id;
            $eligible[] = [$comment, $commentData['parent_id']];
            $result['imported']++;
        }

        foreach ($eligible as [$comment, $parentWordPressId]) {
            $parentId = $parentWordPressId !== '0' ? ($idMap[$parentWordPressId] ?? null) : null;
            if ($parentId !== $comment->id && $comment->parent_id !== $parentId) {
                $comment->update(['parent_id' => $parentId]);
            }
        }

        return $result;
    }

    /** @param array<string, string> $comment */
    private function isImportableComment(array $comment): bool
    {
        return in_array($comment['type'], ['', 'comment'], true) && $this->plainComment($comment['content']) !== '';
    }

    /** @param array<string, string> $comment */
    private function commentSourceId(array $comment): string
    {
        return $comment['id'] !== ''
            ? $comment['id']
            : 'hash-'.sha1($comment['author_email'].'|'.$comment['date'].'|'.$comment['content']);
    }

    private function plainComment(string $content): string
    {
        return trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function commentStatus(string $status): string
    {
        return match ($status) {
            '1', 'approve', 'approved' => 'approved',
            'spam', 'trash' => 'spam',
            default => 'pending',
        };
    }

    private function commentDate(string $date): ?CarbonImmutable
    {
        if ($date === '' || $date === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }
}

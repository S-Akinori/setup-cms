<?php

namespace App\Services\WordPress;

use App\Models\Category;
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
    public function __construct(
        private readonly WxrReader $reader,
        private readonly HtmlToMarkdown $converter,
        private readonly WordPressImageImporter $imageImporter,
    ) {}

    /**
     * @return array{created: int, updated: int, skipped: int, ignored: int, images: int, missing_images: int, warnings: list<string>}
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
            'warnings' => [],
        ];
        $resolvedImages = [];
        $seenSlugs = [];

        foreach ($items as $item) {
            if ($item['type'] !== 'post') {
                $report['ignored']++;

                continue;
            }

            $slug = $this->slug($item);
            if (isset($seenSlugs[$slug])) {
                $report['skipped']++;
                $report['warnings'][] = "WXR内でslugが重複しているためスキップ: {$slug}";

                continue;
            }
            $seenSlugs[$slug] = true;

            $existing = Post::withTrashed()->where('slug', $slug)->first();
            if ($existing && ! $updateExisting) {
                $report['skipped']++;

                continue;
            }

            if ($dryRun) {
                $report[$existing ? 'updated' : 'created']++;

                continue;
            }

            try {
                DB::transaction(function () use (
                    $item,
                    $slug,
                    $existing,
                    $author,
                    $uploadsDirectory,
                    $attachments,
                    &$resolvedImages,
                    &$report,
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
                    $categoryId = $this->categoryId($item['categories']);
                    $tagIds = $this->tagIds($item['categories']);
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
                        'lock_version' => $existing ? ((int) $existing->lock_version + 1) : 1,
                    ]);
                    $post->save();
                    $post->tags()->sync($tagIds);

                    if (! $existing) {
                        $this->revision($post, $author->id);
                    }
                });

                $report[$existing ? 'updated' : 'created']++;
            } catch (Throwable $error) {
                $report['skipped']++;
                $report['warnings'][] = "{$slug}: {$error->getMessage()}";
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
                $post->only(['slug', 'title', 'description', 'body_markdown', 'status', 'published_at', 'scheduled_at', 'category_id', 'featured_image_id', 'seo_title', 'seo_description', 'canonical_url', 'noindex']),
                ['tag_ids' => $post->tags()->pluck('tags.id')->all()]
            ),
        ]);
    }
}

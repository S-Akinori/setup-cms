<?php

namespace App\Services\WordPress;

use App\Models\Media;
use App\Services\ImageSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class WordPressImageImporter
{
    /** @var array<string, Media> */
    private array $cache = [];

    public function __construct(private readonly ImageSanitizer $sanitizer) {}

    public function import(string $url, string $uploadsDirectory, int $userId, string $alt = ''): ?Media
    {
        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }

        $source = $this->sourcePath($url, $uploadsDirectory);
        if ($source === null || ! is_file($source) || filesize($source) > 10 * 1024 * 1024) {
            return null;
        }

        $mime = mime_content_type($source) ?: '';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $upload = new UploadedFile($source, basename($source), $mime, UPLOAD_ERR_OK, true);
        $result = $this->sanitizer->sanitize($upload, now()->format('Y/m').'/'.Str::uuid());

        $existing = Media::where('sha256', $result['sha256'])->first();
        if ($existing) {
            Storage::disk('public')->delete($result['path']);

            return $this->cache[$url] = $existing;
        }

        return $this->cache[$url] = Media::create(array_merge($result, [
            'disk' => 'public',
            'original_name' => mb_substr(basename($source), 0, 255),
            'alt_text' => mb_substr($alt, 0, 300),
            'uploaded_by' => $userId,
        ]));
    }

    private function sourcePath(string $url, string $uploadsDirectory): ?string
    {
        $root = realpath($uploadsDirectory);
        $path = parse_url($url, PHP_URL_PATH);
        if ($root === false || ! is_string($path)) {
            return null;
        }

        $marker = '/wp-content/uploads/';
        $position = stripos(str_replace('\\', '/', $path), $marker);
        if ($position === false) {
            return null;
        }

        $relative = rawurldecode(substr($path, $position + strlen($marker)));
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $relative)), fn ($part) => $part !== ''));
        if ($segments === [] || in_array('..', $segments, true)) {
            return null;
        }

        $candidate = realpath($root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments));
        if ($candidate === false) {
            return null;
        }

        $normalizedRoot = rtrim(strtolower(str_replace('\\', '/', $root)), '/').'/';
        $normalizedCandidate = strtolower(str_replace('\\', '/', $candidate));
        if (! str_starts_with($normalizedCandidate, $normalizedRoot)) {
            throw new RuntimeException('uploadsディレクトリ外の画像は読み込めません。');
        }

        return $candidate;
    }
}

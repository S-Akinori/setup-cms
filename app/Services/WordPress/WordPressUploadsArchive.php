<?php

namespace App\Services\WordPress;

use RuntimeException;
use ZipArchive;

class WordPressUploadsArchive
{
    private const MAX_FILES = 20000;

    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    private const MAX_EXPANDED_SIZE = 2 * 1024 * 1024 * 1024;

    /**
     * @param  list<string>  $requiredPaths
     * @return array{directory: string, files: int}
     */
    public function extract(string $archivePath, string $destination, array $requiredPaths): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP拡張が有効になっていません。サーバーのPHP設定を確認してください。');
        }

        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('画像ZIPを開けませんでした。ZIPファイルが破損していないか確認してください。');
        }
        if ($requiredPaths === []) {
            $zip->close();

            return ['directory' => $destination, 'files' => 0];
        }

        $required = array_fill_keys(array_map(fn (string $path) => $this->normalizeRequiredPath($path), $requiredPaths), true);
        $selectedEntries = [];
        $selectedPaths = [];
        $rootPrefix = null;
        $expandedSize = 0;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat) || ! isset($stat['name'], $stat['size'])) {
                    throw new RuntimeException('画像ZIP内のファイル情報を読み取れませんでした。');
                }

                $entryName = (string) $stat['name'];
                if (str_ends_with(str_replace('\\', '/', $entryName), '/')) {
                    continue;
                }

                $image = $this->imageEntry($entryName);
                if ($image === null || ! isset($required[$image['path']]) || isset($selectedPaths[$image['path']])) {
                    continue;
                }

                $size = (int) $stat['size'];
                if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
                    continue;
                }
                if (($stat['encryption_method'] ?? 0) !== 0 || $this->isSymbolicLink($zip, $index)) {
                    throw new RuntimeException('画像ZIPに暗号化ファイルまたはシンボリックリンクが含まれています。');
                }

                $rootPrefix ??= $image['root'];
                if ($rootPrefix !== $image['root']) {
                    throw new RuntimeException('画像ZIP内に複数のuploadsフォルダがあります。1つのuploadsフォルダだけをZIPにしてください。');
                }

                $selectedEntries[] = $entryName;
                $selectedPaths[$image['path']] = true;
                $expandedSize += $size;
                if (count($selectedEntries) > self::MAX_FILES || $expandedSize > self::MAX_EXPANDED_SIZE) {
                    throw new RuntimeException('画像ZIPの展開後サイズまたはファイル数が上限を超えています。');
                }
            }

            if ($selectedEntries !== [] && ! $zip->extractTo($destination, $selectedEntries)) {
                throw new RuntimeException('画像ZIPを展開できませんでした。storageディレクトリの権限を確認してください。');
            }
        } finally {
            $zip->close();
        }

        $directory = $destination;
        if (is_string($rootPrefix) && $rootPrefix !== '') {
            $directory .= DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rootPrefix);
        }

        return ['directory' => $directory, 'files' => count($selectedEntries)];
    }

    /** @return null|array{path: string, root: string} */
    private function imageEntry(string $entryName): ?array
    {
        if (str_contains($entryName, '\\')) {
            throw new RuntimeException('画像ZIP内のパス区切りには「/」を使用してください。ZIPを作り直してください。');
        }
        if (str_contains($entryName, "\0") || str_starts_with($entryName, '/') || preg_match('/^[A-Za-z]:\//', $entryName) === 1) {
            throw new RuntimeException('画像ZIPに不正な絶対パスが含まれています。');
        }

        $segments = array_values(array_filter(explode('/', $entryName), fn (string $segment) => $segment !== ''));
        if ($segments === [] || in_array('..', $segments, true)) {
            throw new RuntimeException('画像ZIPに不正な相対パスが含まれています。');
        }
        foreach ($segments as $segment) {
            if ($segment === '.' || preg_match('/[<>:"|?*\x00-\x1F]/u', $segment) === 1) {
                throw new RuntimeException('画像ZIPに使用できないファイル名が含まれています。');
            }
        }

        $extension = strtolower(pathinfo(end($segments), PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }

        $rootLength = 0;
        $lowerSegments = array_map('strtolower', $segments);
        $uploadsIndex = array_search('uploads', $lowerSegments, true);
        if ($uploadsIndex !== false) {
            $rootLength = $uploadsIndex + 1;
        } else {
            foreach ($segments as $index => $segment) {
                if (preg_match('/^\d{4}$/', $segment) === 1 && isset($segments[$index + 1]) && preg_match('/^(?:0[1-9]|1[0-2])$/', $segments[$index + 1]) === 1) {
                    $rootLength = $index;
                    break;
                }
            }
        }

        $relativeSegments = array_slice($segments, $rootLength);

        return [
            'path' => $this->normalizeRequiredPath(implode('/', $relativeSegments)),
            'root' => implode('/', array_slice($segments, 0, $rootLength)),
        ];
    }

    private function normalizeRequiredPath(string $path): string
    {
        return ltrim(str_replace('\\', '/', rawurldecode($path)), '/');
    }

    private function isSymbolicLink(ZipArchive $zip, int $index): bool
    {
        $operationsSystem = 0;
        $attributes = 0;
        if (! $zip->getExternalAttributesIndex($index, $operationsSystem, $attributes)) {
            return false;
        }

        return (($attributes >> 16) & 0xF000) === 0xA000;
    }
}

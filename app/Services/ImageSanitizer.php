<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class ImageSanitizer
{
    public function sanitize(UploadedFile $file, string $destinationWithoutExtension): array
    {
        $bytes = file_get_contents($file->getRealPath());
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        $info = @getimagesize($file->getRealPath());

        if ($image === false || $info === false) {
            throw new RuntimeException('画像を安全に読み込めませんでした。');
        }

        $format = match ($info['mime']) {
            'image/jpeg' => ['jpg', 'image/jpeg'],
            'image/png' => ['png', 'image/png'],
            'image/webp' => ['webp', 'image/webp'],
            default => throw new RuntimeException('JPEG、PNG、WebPのみアップロードできます。'),
        };

        $path = $destinationWithoutExtension.'.'.$format[0];
        $absolutePath = storage_path('app/public/'.$path);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }

        $saved = match ($format[1]) {
            'image/jpeg' => imagejpeg($image, $absolutePath, 88),
            'image/png' => imagepng($image, $absolutePath, 7),
            'image/webp' => imagewebp($image, $absolutePath, 88),
        };
        imagedestroy($image);

        if (! $saved) {
            throw new RuntimeException('画像を保存できませんでした。');
        }

        return [
            'path' => $path,
            'mime_type' => $format[1],
            'size' => filesize($absolutePath),
            'width' => $info[0],
            'height' => $info[1],
            'sha256' => hash_file('sha256', $absolutePath),
        ];
    }
}

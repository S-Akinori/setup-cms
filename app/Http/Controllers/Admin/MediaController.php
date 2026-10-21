<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\Audit;
use App\Services\ImageSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class MediaController extends Controller
{
    public function index(): View
    {
        return view('admin.media.index', ['mediaItems' => Media::latest()->paginate(30)->onEachSide(1)]);
    }

    public function store(Request $request, ImageSanitizer $sanitizer): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'alt_text' => ['required', 'string', 'max:300'],
        ], [
            'image.required' => '画像を選択してください。',
            'image.uploaded' => '画像をアップロードできませんでした。ファイルサイズが10MB以下か確認してください。',
            'image.image' => '有効な画像ファイルを選択してください。',
            'image.mimes' => 'JPEG、PNG、WebP形式の画像を選択してください。',
            'image.max' => '画像のファイルサイズは10MB以下にしてください。',
            'alt_text.required' => '代替テキストを入力してください。',
        ]);
        try {
            $result = $sanitizer->sanitize($data['image'], now()->format('Y/m').'/'.Str::uuid());
        } catch (RuntimeException $error) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $error->getMessage(),
                    'errors' => ['image' => [$error->getMessage()]],
                ], 422);
            }

            return back()->withErrors(['image' => $error->getMessage()]);
        }
        $media = Media::create(array_merge($result, [
            'disk' => 'public',
            'original_name' => mb_substr($data['image']->getClientOriginalName(), 0, 255),
            'alt_text' => $data['alt_text'],
            'uploaded_by' => $request->user()->id,
        ]));
        Audit::record($request, 'media.uploaded', $media);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $media->id,
                    'url' => asset('storage/'.$media->path),
                    'alt' => $media->alt_text,
                    'width' => $media->width,
                    'height' => $media->height,
                ],
            ], 201);
        }

        return back()->with('success', '画像をアップロードしました。');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class PreviewController extends Controller
{
    public function __invoke(Request $request, Post $post): RedirectResponse
    {
        $previewUrl = Setting::valueOf('preview_url');
        if ($previewUrl === null) {
            return redirect()->route('admin.settings.edit')
                ->with('warning', '記事をプレビューする前にプレビューURLを設定してください。');
        }

        $contentUrl = URL::temporarySignedRoute(
            'api.preview',
            now()->addMinutes(15),
            ['post' => $post->id],
        );
        $query = http_build_query([
            'cms_preview_url' => $contentUrl,
            'slug' => $post->slug,
            'type' => $post->content_type,
        ], '', '&', PHP_QUERY_RFC3986);
        $separator = str_contains($previewUrl, '?') ? '&' : '?';

        Audit::record($request, $post->content_type.'.previewed', $post);

        return redirect()->away($previewUrl.$separator.$query);
    }
}

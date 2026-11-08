<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignedPreviewController extends Controller
{
    public function __invoke(Request $request, Post $post, ContentController $contentController): JsonResponse
    {
        $post->load(['author', 'category', 'tags', 'featuredImage']);

        return response()->json([
            'data' => $contentController->serialize($post, true),
            'meta' => [
                'preview' => true,
                'expiresAt' => $request->integer('expires'),
            ],
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }
}

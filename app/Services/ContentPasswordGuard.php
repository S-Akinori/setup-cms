<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class ContentPasswordGuard
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function ensureAccess(Post $post, Request $request): void
    {
        if (! $post->isPasswordProtected()) {
            return;
        }

        $password = $request->header('X-Content-Password');
        if (! is_string($password) || $password === '') {
            $this->deny('CONTENT_PASSWORD_REQUIRED', 401);
        }

        $key = 'content-password:'.hash('sha256', $post->id.'|'.($request->ip() ?? 'unknown'));
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $retryAfter = RateLimiter::availableIn($key);
            throw new HttpResponseException(
                response()->json([
                    'message' => '試行回数が上限に達しました。しばらく待ってから再試行してください。',
                    'code' => 'CONTENT_PASSWORD_RATE_LIMITED',
                    'passwordRequired' => true,
                ], 429)->header('Cache-Control', 'no-store')->header('Retry-After', (string) $retryAfter)
            );
        }

        if (! Hash::check($password, $post->access_password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->deny('CONTENT_PASSWORD_REQUIRED', 401);
        }

        RateLimiter::clear($key);
    }

    private function deny(string $code, int $status): never
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'この記事を表示するには正しいパスワードが必要です。',
                'code' => $code,
                'passwordRequired' => true,
            ], $status)->header('Cache-Control', 'no-store')
        );
    }
}

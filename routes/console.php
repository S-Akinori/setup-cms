<?php

use App\Models\Post;
use App\Services\BuildDispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('cms:publish-scheduled', function () {
    $count = Post::where('status', 'scheduled')->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())
        ->update(['status' => 'published', 'published_at' => now(), 'scheduled_at' => null]);
    if ($count > 0) {
        app(BuildDispatcher::class)->dispatch('scheduled.publish', ['count' => $count]);
    }
    $this->info("{$count}件を公開しました。");
})->purpose('公開予約時刻を過ぎた記事を公開する');

Schedule::command('cms:publish-scheduled')->everyFiveMinutes()->withoutOverlapping();

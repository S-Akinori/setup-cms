<?php

namespace App\Services;

use App\Models\BuildJob;
use Illuminate\Support\Facades\Http;
use Throwable;

class BuildDispatcher
{
    public function dispatch(string $reason, array $payload = []): BuildJob
    {
        $job = BuildJob::create(['status' => 'pending', 'reason' => $reason, 'payload' => $payload]);
        $url = config('services.frontend_build.url');

        if (! $url) {
            return $job;
        }

        try {
            $response = Http::asJson()
                ->withToken((string) config('services.frontend_build.token'))
                ->timeout(10)
                ->post($url, ['job_id' => $job->id, 'reason' => $reason, 'payload' => $payload]);

            $response->throw();
            $job->update(['status' => 'running']);
        } catch (Throwable $error) {
            report($error);
            $job->update(['status' => 'failed', 'error' => mb_substr($error->getMessage(), 0, 2000), 'finished_at' => now()]);
        }

        return $job;
    }
}

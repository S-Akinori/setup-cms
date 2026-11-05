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

        if ($this->hasGitHubConfiguration()) {
            return $this->dispatchToGitHub($job, $reason, $payload);
        }

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

    private function hasGitHubConfiguration(): bool
    {
        return collect([
            config('services.github_deploy.token'),
            config('services.github_deploy.owner'),
            config('services.github_deploy.repository'),
        ])->contains(fn ($value) => filled($value));
    }

    private function dispatchToGitHub(BuildJob $job, string $reason, array $payload): BuildJob
    {
        $token = (string) config('services.github_deploy.token');
        $owner = (string) config('services.github_deploy.owner');
        $repository = (string) config('services.github_deploy.repository');
        $eventType = (string) config('services.github_deploy.event_type', 'cms-published');

        $validRepository = preg_match('/\A[A-Za-z0-9_.-]+\z/', $owner) === 1
            && preg_match('/\A[A-Za-z0-9_.-]+\z/', $repository) === 1;

        if ($token === '' || ! $validRepository || $eventType === '' || mb_strlen($eventType) > 100) {
            $job->update([
                'status' => 'failed',
                'error' => 'GitHub自動デプロイの環境変数が不足しているか、形式が正しくありません。',
                'finished_at' => now(),
            ]);

            return $job;
        }

        try {
            $response = Http::withToken($token)
                ->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->retry(3, 500)
                ->timeout(10)
                ->post("https://api.github.com/repos/{$owner}/{$repository}/dispatches", [
                    'event_type' => $eventType,
                    'client_payload' => [
                        'job_id' => $job->id,
                        'reason' => $reason,
                        'content' => $payload,
                        'cms_url' => config('app.url'),
                        'dispatched_at' => now()->toIso8601String(),
                    ],
                ]);

            $response->throw();
            $job->update(['status' => 'running']);
        } catch (Throwable $error) {
            report($error);
            $job->update([
                'status' => 'failed',
                'error' => mb_substr($error->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
        }

        return $job;
    }
}

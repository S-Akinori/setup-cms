<?php

namespace Tests\Feature;

use App\Services\BuildDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BuildDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_the_astro_deploy_workflow_through_github(): void
    {
        config()->set('app.url', 'https://cms.example.com');
        config()->set('services.github_deploy', [
            'token' => 'github-token',
            'owner' => 'S-Akinori',
            'repository' => 'physics-setup-blog',
            'event_type' => 'cms-published',
        ]);
        Http::fake([
            'api.github.com/*' => Http::response(status: 204),
        ]);

        $job = app(BuildDispatcher::class)->dispatch('post.updated', ['post_id' => '01TEST']);

        $this->assertSame('running', $job->fresh()->status);
        Http::assertSent(function (Request $request) use ($job): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.github.com/repos/S-Akinori/physics-setup-blog/dispatches'
                && $request->hasHeader('Authorization', 'Bearer github-token')
                && $request->hasHeader('Accept', 'application/vnd.github+json')
                && $request['event_type'] === 'cms-published'
                && $request['client_payload']['job_id'] === $job->id
                && $request['client_payload']['reason'] === 'post.updated'
                && $request['client_payload']['content']['post_id'] === '01TEST'
                && $request['client_payload']['cms_url'] === 'https://cms.example.com';
        });
    }

    public function test_incomplete_github_configuration_is_recorded_as_a_failed_build(): void
    {
        config()->set('services.github_deploy', [
            'token' => 'github-token',
            'owner' => '',
            'repository' => '',
            'event_type' => 'cms-published',
        ]);
        Http::fake();

        $job = app(BuildDispatcher::class)->dispatch('post.updated');

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertNotNull($job->fresh()->finished_at);
        $this->assertStringContainsString('環境変数が不足', $job->fresh()->error);
        Http::assertNothingSent();
    }
}

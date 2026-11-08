<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\BuildDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BuildController extends Controller
{
    public function __invoke(Request $request, BuildDispatcher $dispatcher): RedirectResponse
    {
        $job = $dispatcher->dispatch('manual.publish', [
            'requested_by' => $request->user()->id,
        ]);
        Audit::record($request, 'build.requested', $job, ['status' => $job->status]);

        return match ($job->status) {
            'running' => back()->with('success', 'ブログの更新を開始しました。GitHub Actionsでビルド状況を確認できます。'),
            'failed' => back()->with('warning', 'ブログの更新を開始できませんでした。GitHub設定とLaravelログを確認してください。'),
            default => back()->with('warning', '更新リクエストを保存しましたが、GitHub自動デプロイが設定されていません。'),
        };
    }
}

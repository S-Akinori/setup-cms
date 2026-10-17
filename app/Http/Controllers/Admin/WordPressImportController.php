<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\BuildDispatcher;
use App\Services\WordPress\WordPressImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class WordPressImportController extends Controller
{
    public function create(): View
    {
        return view('admin.wordpress-import.create');
    }

    public function store(Request $request, WordPressImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'wxr' => ['required', 'file', 'max:51200', 'extensions:xml'],
            'mode' => ['required', Rule::in(['dry-run', 'import'])],
            'update_existing' => ['nullable', 'boolean'],
        ], [
            'wxr.required' => 'WordPressのWXR/XMLファイルを選択してください。',
            'wxr.uploaded' => 'ファイルをアップロードできませんでした。サーバーのアップロード上限を確認してください。',
            'wxr.max' => 'WXR/XMLファイルは50MB以下にしてください。',
            'wxr.extensions' => '拡張子が.xmlのファイルを選択してください。',
        ]);

        $storedPath = $data['wxr']->storeAs('imports', Str::uuid().'.xml', 'local');
        if (! is_string($storedPath)) {
            throw new RuntimeException('一時ファイルを保存できませんでした。storageディレクトリの権限を確認してください。');
        }

        $dryRun = $data['mode'] === 'dry-run';

        try {
            $report = $importer->import(
                Storage::disk('local')->path($storedPath),
                $request->user(),
                null,
                (bool) ($data['update_existing'] ?? false),
                $dryRun,
            );
        } catch (Throwable $error) {
            report($error);

            return back()->withInput()->withErrors([
                'wxr' => 'インポートできませんでした: '.$error->getMessage(),
            ]);
        } finally {
            Storage::disk('local')->delete($storedPath);
        }

        Audit::record($request, $dryRun ? 'wordpress_import.previewed' : 'wordpress_import.completed', null, [
            'created' => $report['created'],
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
            'ignored' => $report['ignored'],
            'comments_imported' => $report['comments_imported'],
            'comments_skipped' => $report['comments_skipped'],
        ]);

        if (! $dryRun && ($report['created'] > 0 || $report['updated'] > 0)) {
            app(BuildDispatcher::class)->dispatch('wordpress.imported', [
                'created' => $report['created'],
                'updated' => $report['updated'],
            ]);
        }

        return back()
            ->with('success', $dryRun ? '事前確認が完了しました。データは変更していません。' : 'WordPressデータをインポートしました。')
            ->with('wordpress_import_report', $report)
            ->with('wordpress_import_dry_run', $dryRun);
    }
}

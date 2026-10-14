<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WordPress\WordPressImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportWordPress extends Command
{
    protected $signature = 'cms:import-wordpress
                            {file : WordPressからエクスポートしたWXR/XMLファイル}
                            {--author-email= : 移行後の記事投稿者にする管理者メールアドレス}
                            {--uploads= : WordPressのwp-content/uploadsディレクトリ}
                            {--update-existing : 同じslugの記事を上書きする}
                            {--dry-run : データベースや画像を変更せず対象件数を確認する}';

    protected $description = 'WordPressのWXR/XMLから投稿、カテゴリー、タグ、画像を移行する';

    public function handle(WordPressImporter $importer): int
    {
        $file = $this->absolutePath((string) $this->argument('file'));
        $uploads = $this->option('uploads');
        $uploads = is_string($uploads) && $uploads !== '' ? $this->absolutePath($uploads) : null;

        $author = $this->author();
        if (! $author) {
            return self::FAILURE;
        }
        if (! is_file($file)) {
            $this->error("WXRファイルが見つかりません: {$file}");

            return self::FAILURE;
        }
        if ($uploads !== null && ! is_dir($uploads)) {
            $this->error("uploadsディレクトリが見つかりません: {$uploads}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'WordPress移行の事前確認を開始します。' : 'WordPress移行を開始します。');

        try {
            $report = $importer->import(
                $file,
                $author,
                $uploads,
                (bool) $this->option('update-existing'),
                $dryRun,
            );
        } catch (Throwable $error) {
            $this->error('移行を開始できませんでした: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->table(['結果', '件数'], [
            ['新規記事', $report['created']],
            ['更新記事', $report['updated']],
            ['スキップ', $report['skipped']],
            ['対象外データ', $report['ignored']],
            ['コピーした画像', $report['images']],
            ['見つからない画像', $report['missing_images']],
        ]);

        foreach (array_slice($report['warnings'], 0, 20) as $warning) {
            $this->warn($warning);
        }
        if (count($report['warnings']) > 20) {
            $this->warn('ほか'.(count($report['warnings']) - 20).'件の警告があります。');
        }

        if ($dryRun) {
            $this->info('事前確認のため、データは変更していません。');
        } else {
            $this->info('WordPressからの移行が完了しました。');
        }

        return self::SUCCESS;
    }

    private function author(): ?User
    {
        $email = $this->option('author-email');
        if (is_string($email) && $email !== '') {
            $user = User::where('email', mb_strtolower($email))->where('is_admin', true)->first();
            if (! $user) {
                $this->error("管理者が見つかりません: {$email}");
            }

            return $user;
        }

        $admins = User::where('is_admin', true)->limit(2)->get();
        if ($admins->count() === 1) {
            $this->line("投稿者: {$admins->first()->email}");

            return $admins->first();
        }

        $this->error($admins->isEmpty()
            ? '管理者がいません。先に cms:create-admin を実行してください。'
            : '管理者が複数います。--author-email を指定してください。');

        return null;
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}

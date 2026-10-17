<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class WordPressImportWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_a_wordpress_import_without_writing_data(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/wordpress-import')
            ->assertOk()
            ->assertSee('WordPressインポート')
            ->assertSee('事前確認（データを変更しない）');

        $response = $this->actingAs($admin)->post('/admin/wordpress-import', [
            'wxr' => $this->wxrUpload(),
            'mode' => 'dry-run',
        ]);

        $response->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('wordpress_import_dry_run', true)
            ->assertSessionHas('wordpress_import_report', fn (array $report) => $report['created'] === 2
                && $report['ignored'] === 1
                && $report['comments_imported'] === 2
                && $report['comments_skipped'] === 1
                && $report['type_counts']['created'] === ['post' => 1, 'page' => 1]
                && $report['type_counts']['ignored'] === ['nav_menu_item' => 1]
                && $report['items_total'] === 3
                && count($report['items']) === 3
                && $report['items'][0]['title'] === '最初の記事'
                && $report['items'][0]['action'] === 'created'
                && $report['items'][1]['type'] === 'page'
                && $report['items'][2]['title'] === 'メインメニュー'
                && $report['items'][2]['type'] === 'nav_menu_item'
                && $report['items'][2]['action'] === 'ignored'
                && $report['items'][2]['reason'] === 'WordPressのメニュー項目');
        $this->get('/admin/wordpress-import')
            ->assertOk()
            ->assertSeeText('投稿 1')
            ->assertSeeText('固定 1')
            ->assertSeeText('メニュー 1');
        $this->assertDatabaseCount('posts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    public function test_admin_can_import_wordpress_posts_and_pages(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post('/admin/wordpress-import', [
            'wxr' => $this->wxrUpload(),
            'mode' => 'import',
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Post::articles()->count());
        $this->assertSame(1, Post::pages()->count());
        $this->assertDatabaseHas('categories', ['slug' => 'news']);
        $this->assertDatabaseHas('tags', ['slug' => 'wordpress']);
        $this->assertDatabaseHas('comments', ['author_name' => '山田太郎', 'status' => 'approved']);
        $this->assertDatabaseHas('comments', ['author_name' => '佐藤花子', 'status' => 'pending']);
        $this->assertDatabaseHas('build_jobs', ['reason' => 'wordpress.imported']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wordpress_import.completed']);
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    public function test_wordpress_import_rejects_non_xml_files(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/wordpress-import', [
            'wxr' => UploadedFile::fake()->createWithContent('export.txt', 'not xml'),
            'mode' => 'import',
        ])->assertSessionHasErrors('wxr');
    }

    public function test_admin_can_import_images_from_an_uploads_zip(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);
        $xml = file_get_contents(base_path('tests/Fixtures/wordpress-export.xml'));
        $xml = str_replace(
            '</p><script>',
            '<img src="https://old.example.com/wp-content/uploads/2024/05/article.jpg" alt="記事画像"></p><script>',
            $xml,
        );

        $response = $this->actingAs($admin)->post('/admin/wordpress-import', [
            'wxr' => UploadedFile::fake()->createWithContent('wordpress-export.xml', $xml),
            'uploads_zip' => $this->uploadsZip(),
            'mode' => 'import',
        ]);

        try {
            $response->assertRedirect()
                ->assertSessionHasNoErrors()
                ->assertSessionHas('wordpress_import_report', fn (array $report) => $report['images'] === 1 && $report['missing_images'] === 0);
            $this->assertDatabaseHas('media', ['original_name' => 'article.jpg', 'alt_text' => '記事画像']);
            $this->assertStringContainsString('/storage/', Post::where('slug', 'first-post')->sole()->body_markdown);
            $this->assertSame([], Storage::disk('local')->allFiles('imports'));
        } finally {
            foreach (Media::pluck('path') as $path) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    public function test_invalid_uploads_zip_has_a_clear_error(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/wordpress-import', [
            'wxr' => $this->wxrUpload(),
            'uploads_zip' => UploadedFile::fake()->createWithContent('uploads.zip', 'not-a-zip'),
            'mode' => 'import',
        ])->assertSessionHasErrors('uploads_zip');

        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    public function test_oversized_wordpress_import_has_a_clear_error(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withServerVariables(['CONTENT_LENGTH' => 566 * 1024 * 1024])
            ->actingAs($admin)
            ->post('/admin/wordpress-import')
            ->assertStatus(413)
            ->assertSeeText('WordPressのWXR/XMLは50MB以下、画像ZIPは512MB以下にしてください。サーバーのpost_max_sizeも確認してください。');
    }

    private function wxrUpload(): UploadedFile
    {
        $xml = file_get_contents(base_path('tests/Fixtures/wordpress-export.xml'));
        $ignoredItem = <<<'XML'
<item>
    <title>メインメニュー</title>
    <wp:post_id>12</wp:post_id>
    <wp:post_name>main-menu</wp:post_name>
    <wp:status>publish</wp:status>
    <wp:post_type>nav_menu_item</wp:post_type>
</item>
XML;

        return UploadedFile::fake()->createWithContent(
            'wordpress-export.xml',
            "\xEF\xBB\xBF\r\n".str_replace('</channel>', $ignoredItem."\n</channel>", $xml),
        );
    }

    private function uploadsZip(): UploadedFile
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $temporaryPath = $directory.DIRECTORY_SEPARATOR.'uploads-'.bin2hex(random_bytes(8)).'.zip';

        $image = UploadedFile::fake()->image('article.jpg', 640, 360);
        $zip = new ZipArchive;
        $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('wp-content/uploads/2024/05/article.jpg', file_get_contents($image->getRealPath()));
        $zip->close();
        $contents = file_get_contents($temporaryPath);
        unlink($temporaryPath);

        return UploadedFile::fake()->createWithContent('uploads.zip', $contents);
    }
}

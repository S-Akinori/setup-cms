@extends('layouts.app')
@section('title', 'WordPressインポート')
@section('content')
<div class="page-head">
    <div><p class="eyebrow">MIGRATION</p><h1>WordPressインポート</h1></div>
</div>

<div class="management-grid import-layout">
    <section class="panel stack">
        <div>
            <h2>WXR/XMLをアップロード</h2>
            <p class="muted">WordPressの「ツール → エクスポート」で取得したファイルを使用します。</p>
        </div>

        <form method="post" action="{{ route('admin.wordpress-import.store') }}" enctype="multipart/form-data" class="stack">
            @csrf
            <label>WXR/XMLファイル
                <input type="file" name="wxr" accept=".xml,application/xml,text/xml" required>
                <small>最大50MB。アップロード後の一時ファイルは処理完了時に削除されます。</small>
            </label>

            <label>実行方法
                <select name="mode">
                    <option value="dry-run" @selected(old('mode', 'dry-run') === 'dry-run')>事前確認（データを変更しない）</option>
                    <option value="import" @selected(old('mode') === 'import')>本番インポート</option>
                </select>
            </label>

            <label class="check">
                <input type="checkbox" name="update_existing" value="1" @checked(old('update_existing'))>
                同じスラッグの既存コンテンツを更新する
            </label>

            <button class="button primary" type="submit">実行する</button>
        </form>

        <div class="import-notes">
            <h2>取り込まれるデータ</h2>
            <ul>
                <li>投稿、固定ページ、カテゴリー、タグ、コメント</li>
                <li>公開状態、公開日時、概要、主なYoast SEO項目</li>
                <li>HTML本文はMarkdownへ自動変換</li>
            </ul>
            <p>この画面からの取込では画像ファイル自体はコピーされず、本文内の旧URLを保持します。画像も移行する場合はREADMEのCLI移行手順を使用してください。</p>
        </div>
    </section>

    <section class="stack">
        @if(session('wordpress_import_report'))
            @php($report = session('wordpress_import_report'))
            @php($typeLabels = ['post' => '投稿', 'page' => '固定', 'attachment' => '添付', 'nav_menu_item' => 'メニュー', 'revision' => '履歴', 'custom_css' => 'CSS', 'customize_changeset' => '設定', 'unknown' => '不明'])
            <div class="panel stack">
                <div>
                    <p class="eyebrow">{{ session('wordpress_import_dry_run') ? 'PREVIEW' : 'RESULT' }}</p>
                    <h2>インポート結果</h2>
                </div>
                <dl class="result-grid">
                    <div>
                        <dt>新規</dt><dd>{{ $report['created'] }}</dd>
                        @if($report['type_counts']['created'] ?? [])
                            <span class="result-breakdown">
                                @foreach($report['type_counts']['created'] as $type => $count)
                                    <small>{{ $typeLabels[$type] ?? $type }} {{ $count }}</small>
                                @endforeach
                            </span>
                        @endif
                    </div>
                    <div><dt>更新</dt><dd>{{ $report['updated'] }}</dd></div>
                    <div><dt>スキップ</dt><dd>{{ $report['skipped'] }}</dd></div>
                    <div>
                        <dt>対象外</dt><dd>{{ $report['ignored'] }}</dd>
                        @if($report['type_counts']['ignored'] ?? [])
                            <span class="result-breakdown">
                                @foreach($report['type_counts']['ignored'] as $type => $count)
                                    <small>{{ $typeLabels[$type] ?? $type }} {{ $count }}</small>
                                @endforeach
                            </span>
                        @endif
                    </div>
                    <div><dt>画像</dt><dd>{{ $report['images'] }}</dd></div>
                    <div><dt>画像不足</dt><dd>{{ $report['missing_images'] }}</dd></div>
                    <div><dt>コメント</dt><dd>{{ $report['comments_imported'] ?? 0 }}</dd></div>
                    <div><dt>コメント除外</dt><dd>{{ $report['comments_skipped'] ?? 0 }}</dd></div>
                </dl>

                @if($report['items'] ?? [])
                    @php($actionLabels = ['created' => '新規', 'updated' => '更新', 'skipped' => 'スキップ', 'ignored' => '対象外'])
                    @php($statusLabels = ['draft' => '下書き', 'scheduled' => '予約', 'published' => '公開', 'archived' => 'アーカイブ'])
                    <div class="import-detail-head">
                        <h2>コンテンツ詳細</h2>
                        <span>{{ count($report['items']) }} / {{ $report['items_total'] ?? count($report['items']) }}件</span>
                    </div>
                    <div class="table-wrap import-table-wrap">
                        <table class="import-table">
                            <thead><tr><th>種類</th><th>タイトル／スラッグ</th><th>状態</th><th>処理</th></tr></thead>
                            <tbody>
                            @foreach($report['items'] as $item)
                                <tr>
                                    <td><span class="content-type">{{ $typeLabels[$item['type']] ?? $item['type'] }}</span></td>
                                    <td><strong>{{ $item['title'] }}</strong><small>/{{ $item['slug'] }}</small>@if($item['reason'])<small class="import-reason">{{ $item['reason'] }}</small>@endif</td>
                                    <td>{{ $statusLabels[$item['status']] ?? $item['status'] }}</td>
                                    <td><span class="import-action {{ $item['action'] }}">{{ $actionLabels[$item['action']] ?? $item['action'] }}</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($report['items_truncated'] ?? false)
                        <p class="muted">一覧は先頭500件まで表示しています。残り{{ ($report['items_total'] ?? count($report['items'])) - count($report['items']) }}件は件数集計に含まれています。</p>
                    @endif
                @endif

                @if($report['warnings'])
                    <div class="notice warning import-warnings">
                        <strong>確認が必要な項目</strong>
                        <ul>@foreach(array_slice($report['warnings'], 0, 20) as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                        @if(count($report['warnings']) > 20)<p>ほか{{ count($report['warnings']) - 20 }}件</p>@endif
                    </div>
                @endif

                @if(session('wordpress_import_dry_run'))
                    <p class="muted">内容に問題がなければ、同じファイルを選び「本番インポート」で実行してください。</p>
                @endif
            </div>
        @else
            <div class="panel import-empty">
                <p class="eyebrow">SAFE MIGRATION</p>
                <h2>最初に事前確認を実行してください</h2>
                <p>対象件数と警告を確認してから本番インポートへ進めます。同じスラッグは初期設定ではスキップされるため、繰り返し実行しても重複しません。</p>
            </div>
        @endif
    </section>
</div>
@endsection

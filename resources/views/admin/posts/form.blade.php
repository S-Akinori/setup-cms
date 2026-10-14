@php($editing = isset($post))
@if($editing)<input type="hidden" name="lock_version" value="{{ old('lock_version', $post->lock_version) }}">@endif

<div class="editor-grid">
    <section class="panel editor-main">
        <label class="title-field">
            <span>タイトル</span>
            <input name="title" value="{{ old('title', $post->title ?? '') }}" required maxlength="200" data-title placeholder="記事タイトル">
        </label>

        <div class="markdown-workspace">
            <div class="editor-heading">
                <div>
                    <strong>Markdown本文</strong>
                    <span>Markdownを入力すると、その場で表示が切り替わります</span>
                </div>
                <span class="latex-hint">LaTeX: $...$ / $$...$$</span>
            </div>

            <div class="markdown-toolbar" role="toolbar" aria-label="Markdownコマンド">
                <button type="button" class="toolbar-button" data-markdown-command="h2" title="見出し2を挿入" disabled>H2</button>
                <button type="button" class="toolbar-button" data-markdown-command="h3" title="見出し3を挿入" disabled>H3</button>
                <button type="button" class="toolbar-button" data-markdown-command="ul" title="箇条書きを挿入" disabled>li</button>
                <button type="button" class="toolbar-button" data-markdown-command="ol" title="番号付きリストを挿入" disabled>ol</button>
                <span class="toolbar-divider" aria-hidden="true"></span>
                <button type="button" class="toolbar-button image-command" data-image-command title="画像を選択して挿入" disabled>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v13H4zM7 15l3.2-3.2 2.5 2.5 1.8-1.8L18 16M8.5 9a1.25 1.25 0 1 0 0 .01"/></svg>
                    画像
                </button>
                <input class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp" multiple data-image-input tabindex="-1">
            </div>

            <div class="markdown-editor-shell" data-upload-url="{{ route('admin.media.store') }}">
                <div class="rich-markdown-editor" data-rich-markdown-editor></div>
                <textarea class="markdown-storage" name="body_markdown" rows="28" data-markdown hidden>{{ old('body_markdown', $post->body_markdown ?? '') }}</textarea>
                <p class="editor-loading" data-editor-loading>エディタを読み込んでいます。</p>
            </div>

            <div class="editor-foot">
                <p class="editor-help">先頭で「## 」「- 」などを入力すると、その場で見出しやリストへ切り替わります。画像はドラッグ＆ドロップにも対応しています。</p>
                <p class="upload-status" data-upload-status role="status" aria-live="polite"></p>
            </div>
        </div>

        <dialog class="media-dialog" data-media-dialog aria-labelledby="media-dialog-title">
            <div class="media-dialog-layout">
                <header class="media-dialog-header">
                    <div>
                        <h2 id="media-dialog-title">画像を挿入</h2>
                        <p>アップロード済みの画像を選ぶか、新しい画像を追加できます。</p>
                    </div>
                    <button type="button" class="dialog-close" data-media-dialog-close aria-label="閉じる">&times;</button>
                </header>

                <div class="media-dialog-actions">
                    <button type="button" class="button primary" data-image-upload-trigger>新しい画像をアップロード</button>
                    <span>JPEG・PNG・WebP、10MBまで</span>
                </div>

                <div class="media-library" aria-label="アップロード済み画像">
                    @forelse($mediaItems as $media)
                        <button
                            type="button"
                            class="media-library-item"
                            data-media-select
                            data-media-url="{{ asset('storage/'.$media->path) }}"
                            data-media-alt="{{ $media->alt_text }}"
                            title="{{ $media->alt_text }}を挿入"
                        >
                            <img src="{{ asset('storage/'.$media->path) }}" alt="" loading="lazy">
                            <span>{{ $media->alt_text }}</span>
                            <small>{{ $media->width }} × {{ $media->height }} px</small>
                        </button>
                    @empty
                        <div class="media-library-empty">
                            <strong>アップロード済みの画像はありません</strong>
                            <span>上のボタンから最初の画像を追加してください。</span>
                        </div>
                    @endforelse
                </div>
            </div>
        </dialog>

        <dialog class="alt-dialog" data-alt-dialog aria-labelledby="alt-dialog-title">
            <div class="stack">
                <div>
                    <h2 id="alt-dialog-title">画像の説明</h2>
                    <p>画像が見えない場合にも内容が伝わる、短い説明を入力してください。</p>
                </div>
                <label for="dropped-image-alt">代替テキスト</label>
                <input id="dropped-image-alt" type="text" maxlength="300" data-alt-input>
                <div class="actions dialog-actions">
                    <button type="button" class="button secondary" data-alt-cancel>キャンセル</button>
                    <button type="button" class="button primary" data-alt-confirm>画像を挿入</button>
                </div>
            </div>
        </dialog>
    </section>

    <aside class="stack editor-sidebar">
        <section class="panel stack">
            <h2>記事設定</h2>
            <label>スラッグ<input name="slug" value="{{ old('slug', $post->slug ?? '') }}" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" data-slug></label>
            <label>概要<textarea name="description" rows="3" maxlength="500">{{ old('description', $post->description ?? '') }}</textarea></label>
            <label>状態<select name="status">@foreach(['draft'=>'下書き','scheduled'=>'予約公開','published'=>'公開','archived'=>'保管'] as $value=>$label)<option value="{{ $value }}" @selected(old('status', $post->status ?? 'draft')===$value)>{{ $label }}</option>@endforeach</select></label>
        </section>

        <section class="panel stack">
            <h2>分類・公開</h2>
            <label>カテゴリ<input name="category" value="{{ old('category', $post->category?->name ?? '') }}"></label>
            <label>タグ <small>カンマ区切り</small><input name="tags" value="{{ old('tags', isset($post) ? $post->tags->pluck('name')->join(', ') : '') }}"></label>
            <label>公開日時<input type="datetime-local" name="published_at" value="{{ old('published_at', isset($post) && $post->published_at ? $post->published_at->format('Y-m-d\TH:i') : '') }}"></label>
            <label>予約日時<input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', isset($post) && $post->scheduled_at ? $post->scheduled_at->format('Y-m-d\TH:i') : '') }}"></label>
            <label>アイキャッチ<select name="featured_image_id"><option value="">なし</option>@foreach($mediaItems as $media)<option value="{{ $media->id }}" @selected(old('featured_image_id', $post->featured_image_id ?? '')===$media->id)>{{ $media->alt_text }} ({{ $media->original_name }})</option>@endforeach</select></label>
        </section>

        <section class="panel stack">
            <h2>SEO</h2>
            <label>SEOタイトル<input name="seo_title" maxlength="200" value="{{ old('seo_title', $post->seo_title ?? '') }}"></label>
            <label>SEO説明<textarea name="seo_description" rows="3" maxlength="320">{{ old('seo_description', $post->seo_description ?? '') }}</textarea></label>
            <label>Canonical URL<input type="url" name="canonical_url" value="{{ old('canonical_url', $post->canonical_url ?? '') }}"></label>
            <label class="check"><input type="checkbox" name="noindex" value="1" @checked(old('noindex', $post->noindex ?? false))>検索結果に表示しない</label>
        </section>

        <button class="button primary sticky-save" type="submit">{{ $editing ? '変更を保存' : '記事を作成' }}</button>
    </aside>
</div>

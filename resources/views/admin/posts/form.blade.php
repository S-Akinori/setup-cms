@php($editing = isset($post))
@php($isPage = ($contentType ?? 'post') === 'page')
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
                <button type="button" class="toolbar-button" data-content-command="button" title="リンクボタンを挿入" disabled>ボタン</button>
                <button type="button" class="toolbar-button" data-content-command="box" title="囲いボックスを挿入" disabled>囲い</button>
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
                <p class="editor-help">Markdown、画像、URLカードに加え、ツールバーからボタンと囲いボックスを挿入できます。</p>
                <p class="upload-status" data-upload-status role="status" aria-live="polite"></p>
            </div>
        </div>

        <dialog class="media-dialog" data-media-dialog data-library-url="{{ route('admin.media.library') }}" aria-labelledby="media-dialog-title">
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
                    <div class="media-library-search" data-media-search-form role="search">
                        <label class="visually-hidden" for="media-library-query">画像を検索</label>
                        <input id="media-library-query" type="search" maxlength="100" placeholder="代替テキスト・ファイル名で検索" data-media-search>
                        <button type="button" class="button secondary" data-media-search-submit>検索</button>
                    </div>
                </div>

                <div class="media-library" data-media-library aria-label="アップロード済み画像" aria-live="polite">
                    <div class="media-library-empty"><span>画像ギャラリーを読み込んでいます。</span></div>
                </div>
                <footer class="media-library-footer">
                    <span data-media-library-status role="status"></span>
                    <button type="button" class="button secondary" data-media-load-more hidden>さらに読み込む</button>
                </footer>
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
            <h2>{{ $isPage ? '固定ページ設定' : '記事設定' }}</h2>
            <label>スラッグ<input name="slug" value="{{ old('slug', $post->slug ?? '') }}" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" data-slug></label>
            <label>概要<textarea name="description" rows="3" maxlength="500">{{ old('description', $post->description ?? '') }}</textarea></label>
            <label>状態<select name="status">@foreach(['draft'=>'下書き','scheduled'=>'予約公開','published'=>'公開','archived'=>'保管'] as $value=>$label)<option value="{{ $value }}" @selected(old('status', $post->status ?? 'draft')===$value)>{{ $label }}</option>@endforeach</select></label>
        </section>

        <section class="panel stack">
            <h2>分類・公開</h2>
            <label>カテゴリー
                <select name="category_id">
                    <option value="">未分類</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) old('category_id', $post->category_id ?? '') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
            <small><a href="{{ route('admin.categories.index') }}">カテゴリーを追加・編集</a></small>
            @unless($isPage)
                <label>タグ <small>カンマ区切り</small><input name="tags" value="{{ old('tags', isset($post) ? $post->tags->pluck('name')->join(', ') : '') }}"></label>
            @endunless
            <label>公開日時<input type="datetime-local" name="published_at" value="{{ old('published_at', isset($post) && $post->published_at ? $post->published_at->format('Y-m-d\TH:i') : '') }}"></label>
            <label>予約日時<input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', isset($post) && $post->scheduled_at ? $post->scheduled_at->format('Y-m-d\TH:i') : '') }}"></label>
            <input type="hidden" name="comments_enabled" value="0">
            <label class="check"><input type="checkbox" name="comments_enabled" value="1" @checked(old('comments_enabled', $post->comments_enabled ?? true))>コメントを受け付ける</label>
            @php($featuredId = (string) old('featured_image_id', $post->featured_image_id ?? ''))
            @php($featuredMedia = $mediaItems->first(fn ($media) => (string) $media->id === $featuredId) ?? ($post->featuredImage ?? null))
            <div class="featured-picker" data-featured-picker data-upload-url="{{ route('admin.media.store') }}">
                <span class="field-label">アイキャッチ画像</span>
                <input type="hidden" name="featured_image_id" value="{{ $featuredId }}" data-featured-id>
                <div class="featured-preview" data-featured-preview>
                    <img src="{{ $featuredMedia ? asset('storage/'.$featuredMedia->path) : '' }}" alt="{{ $featuredMedia?->alt_text ?? '' }}" data-featured-preview-image @if(!$featuredMedia) hidden @endif>
                    <div class="featured-empty" data-featured-empty @if($featuredMedia) hidden @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v13H4zM7 15l3.2-3.2 2.5 2.5 1.8-1.8L18 16M8.5 9a1.25 1.25 0 1 0 0 .01"/></svg>
                        <span>アイキャッチ画像は未設定です</span>
                    </div>
                </div>
                <p class="featured-caption" data-featured-caption>{{ $featuredMedia?->alt_text ?? 'ギャラリーから選択するか、新しい画像をアップロードしてください。' }}</p>
                <div class="featured-actions">
                    <button type="button" class="button secondary" data-featured-open>画像を選択</button>
                    <button type="button" class="text-danger" data-featured-remove @if(!$featuredMedia) hidden @endif>選択を解除</button>
                </div>

                <dialog class="media-dialog featured-dialog" data-featured-dialog aria-labelledby="featured-dialog-title">
                    <div class="featured-dialog-layout">
                        <header class="media-dialog-header">
                            <div>
                                <h2 id="featured-dialog-title">アイキャッチ画像を選択</h2>
                                <p>アップロード済みの画像を選ぶか、新しい画像を追加します。</p>
                            </div>
                            <button type="button" class="dialog-close" data-featured-close aria-label="閉じる">&times;</button>
                        </header>
                        <section class="featured-upload" aria-labelledby="featured-upload-title">
                            <div><strong id="featured-upload-title">新しい画像をアップロード</strong><small>JPEG・PNG・WebP、10MBまで</small></div>
                            <label>画像<input type="file" accept="image/jpeg,image/png,image/webp" data-featured-file></label>
                            <label>代替テキスト<input type="text" maxlength="300" data-featured-alt placeholder="画像の内容を簡潔に説明"></label>
                            <button type="button" class="button primary" data-featured-upload>アップロードして選択</button>
                            <p class="upload-status featured-upload-status" data-featured-status role="status" aria-live="polite"></p>
                        </section>
                        <div class="media-library featured-library" data-featured-library aria-label="アップロード済み画像">
                            @forelse($mediaItems as $media)
                                <button type="button" class="media-library-item featured-library-item" data-featured-select data-media-id="{{ $media->id }}" data-media-url="{{ asset('storage/'.$media->path) }}" data-media-alt="{{ $media->alt_text }}" aria-pressed="{{ (string) $media->id === $featuredId ? 'true' : 'false' }}">
                                    <img src="{{ asset('storage/'.$media->path) }}" alt="" loading="lazy">
                                    <span>{{ $media->alt_text }}</span>
                                    <small>{{ $media->width }} × {{ $media->height }} px</small>
                                </button>
                            @empty
                                <div class="media-library-empty" data-featured-library-empty><strong>画像はまだありません</strong><span>上のフォームからアップロードしてください。</span></div>
                            @endforelse
                        </div>
                    </div>
                </dialog>
            </div>
        </section>

        <section class="panel stack">
            <h2>SEO</h2>
            <label>SEOタイトル<input name="seo_title" maxlength="200" value="{{ old('seo_title', $post->seo_title ?? '') }}"></label>
            <label>SEO説明<textarea name="seo_description" rows="3" maxlength="320">{{ old('seo_description', $post->seo_description ?? '') }}</textarea></label>
            <label>Canonical URL<input type="url" name="canonical_url" value="{{ old('canonical_url', $post->canonical_url ?? '') }}"></label>
            <label class="check"><input type="checkbox" name="noindex" value="1" @checked(old('noindex', $post->noindex ?? false))>検索結果に表示しない</label>
        </section>

        <button class="button primary sticky-save" type="submit">{{ $editing ? '変更を保存' : ($isPage ? '固定ページを作成' : '記事を作成') }}</button>
    </aside>
</div>

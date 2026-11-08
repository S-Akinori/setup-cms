@extends('layouts.app')
@section('title', '設定')
@section('content')
<div class="page-head settings-head">
    <div><p class="eyebrow">SETTINGS</p><h1>設定</h1><p class="muted">ヘッドレスCMSとフロントサイトの接続先を管理します。</p></div>
</div>

<form method="post" action="{{ route('admin.settings.update') }}" class="panel stack settings-form">
    @csrf
    @method('put')
    <div>
        <h2>記事プレビュー</h2>
        <p class="muted">フロント側に用意したプレビューページのURLを指定します。</p>
    </div>
    <label>プレビューURL
        <input type="url" name="preview_url" maxlength="2048" value="{{ old('preview_url', $previewUrl) }}" placeholder="https://blog.example.com/preview" inputmode="url">
    </label>
    <div class="settings-note">
        <strong>フロント側で受け取る値</strong>
        <p>プレビューを開くと、URLへ<code>cms_preview_url</code>、<code>slug</code>、<code>type</code>が追加されます。<code>cms_preview_url</code>から15分間だけ下書き本文を取得できます。</p>
    </div>
    <div class="settings-actions">
        <button class="button primary" type="submit">設定を保存</button>
        @if($previewUrl)<a class="button secondary" href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer">設定先を開く</a>@endif
    </div>
</form>
@endsection

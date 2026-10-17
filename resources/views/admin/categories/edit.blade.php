@extends('layouts.app')
@section('title', 'カテゴリーを編集')
@section('content')
<div class="page-head"><div><p class="eyebrow">EDIT TAXONOMY</p><h1>{{ $category->name }}</h1></div><a class="button secondary" href="{{ route('admin.categories.index') }}">一覧へ戻る</a></div>

<section class="panel narrow-panel stack">
    <form method="post" action="{{ route('admin.categories.update', $category) }}" class="stack">
        @csrf @method('PUT')
        <label>名前<input name="name" value="{{ old('name', $category->name) }}" required maxlength="100"></label>
        <label>スラッグ<input name="slug" value="{{ old('slug', $category->slug) }}" required maxlength="120" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"></label>
        <p class="muted">このカテゴリーを使用している記事：{{ $category->posts_count }}件</p>
        <button class="button primary" type="submit">変更を保存</button>
    </form>
</section>

<form method="post" action="{{ route('admin.categories.destroy', $category) }}" onsubmit="return confirm('このカテゴリーを削除しますか？使用中の記事は未分類になります。')">
    @csrf @method('DELETE')
    <button class="button danger" type="submit">カテゴリーを削除</button>
</form>
@endsection

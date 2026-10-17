@extends('layouts.app')
@section('title', 'カテゴリー')
@section('content')
<div class="page-head"><div><p class="eyebrow">TAXONOMY</p><h1>カテゴリー</h1></div></div>

<div class="management-grid">
    <section class="panel stack">
        <h2>カテゴリーを追加</h2>
        <form method="post" action="{{ route('admin.categories.store') }}" class="stack">
            @csrf
            <label>名前<input name="name" value="{{ old('name') }}" required maxlength="100" placeholder="例：技術"></label>
            <label>スラッグ<input name="slug" value="{{ old('slug') }}" required maxlength="120" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="technology"></label>
            <button class="button primary" type="submit">カテゴリーを追加</button>
        </form>
    </section>

    <section>
        <div class="table-wrap"><table><thead><tr><th>名前</th><th>スラッグ</th><th>記事数</th><th></th></tr></thead><tbody>
        @forelse($categories as $category)
            <tr>
                <td><strong>{{ $category->name }}</strong></td>
                <td><code>{{ $category->slug }}</code></td>
                <td>{{ $category->posts_count }}</td>
                <td><a href="{{ route('admin.categories.edit', $category) }}">編集</a></td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">カテゴリーはまだありません。</td></tr>
        @endforelse
        </tbody></table></div>
        {{ $categories->links() }}
    </section>
</div>
@endsection

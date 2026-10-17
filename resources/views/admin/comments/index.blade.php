@extends('layouts.app')
@section('title', 'コメント')
@section('content')
<div class="page-head">
    <div><p class="eyebrow">DISCUSSION</p><h1>コメント</h1></div>
</div>

<form method="get" class="filter">
    <label>状態
        <select name="status" onchange="this.form.submit()">
            <option value="">すべて</option>
            @foreach(['pending' => '承認待ち', 'approved' => '承認済み', 'spam' => 'スパム'] as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
</form>

<div class="table-wrap">
    <table class="comment-table">
        <thead><tr><th>投稿者</th><th>コメント</th><th>対象コンテンツ</th><th>状態・操作</th></tr></thead>
        <tbody>
        @forelse($comments as $comment)
            <tr>
                <td><strong>{{ $comment->author_name }}</strong><small>{{ $comment->author_email ?: 'メールなし' }}</small><small>{{ $comment->created_at->format('Y-m-d H:i') }}</small></td>
                <td><p class="comment-body">{{ $comment->body }}</p>@if($comment->parent_id)<small>返信コメント</small>@endif</td>
                <td><strong>{{ $comment->post?->title ?? '削除済み' }}</strong><small>{{ $comment->post?->content_type === 'page' ? '固定ページ' : '記事' }} /{{ $comment->post?->slug }}</small></td>
                <td>
                    <form method="post" action="{{ route('admin.comments.update', $comment) }}" class="comment-actions">
                        @csrf @method('patch')
                        <select name="status" aria-label="コメント状態">
                            @foreach(['pending' => '承認待ち', 'approved' => '承認済み', 'spam' => 'スパム'] as $value => $label)
                                <option value="{{ $value }}" @selected($comment->status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button class="button secondary" type="submit">更新</button>
                    </form>
                    <form method="post" action="{{ route('admin.comments.destroy', $comment) }}" onsubmit="return confirm('このコメントを削除しますか？')">
                        @csrf @method('delete')
                        <button class="text-danger" type="submit">削除</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">コメントはありません。</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
{{ $comments->links() }}
@endsection

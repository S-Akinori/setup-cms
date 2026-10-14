@extends('layouts.app')
@section('title', '記事を編集')
@section('content')
<div class="page-head"><div><p class="eyebrow">EDIT CONTENT</p><h1>{{ $post->title }}</h1></div></div>
<form method="post" action="{{ route('admin.posts.update', $post) }}">@csrf @method('PUT') @include('admin.posts.form')</form>
@if($post->revisions->isNotEmpty())<section class="panel revisions"><h2>変更履歴</h2>@foreach($post->revisions as $revision)<div><span>Version {{ $revision->version }} · {{ $revision->created_at->format('Y-m-d H:i') }}</span><form method="post" action="{{ route('admin.posts.revisions.restore', [$post, $revision]) }}" onsubmit="return confirm('この版を復元しますか？')">@csrf<button class="link-button">復元</button></form></div>@endforeach</section>@endif
<form method="post" action="{{ route('admin.posts.destroy', $post) }}" onsubmit="return confirm('この記事をゴミ箱へ移動しますか？')">@csrf @method('DELETE')<button class="button danger">ゴミ箱へ移動</button></form>
@endsection

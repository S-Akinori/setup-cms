@extends('layouts.app')
@section('title', '画像')
@section('content')
<div class="page-head"><div><p class="eyebrow">MEDIA</p><h1>画像</h1></div></div>
<section class="panel"><form method="post" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" class="upload-form">@csrf<label>画像（JPEG / PNG / WebP、10MBまで）<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label><label>代替テキスト<input name="alt_text" maxlength="300" required></label><button class="button primary">アップロード</button></form></section>
<div class="media-grid">@forelse($mediaItems as $media)<figure><img src="{{ asset('storage/'.$media->path) }}" alt="{{ $media->alt_text }}" loading="lazy"><figcaption>{{ $media->alt_text }}<small>{{ $media->width }}×{{ $media->height }}</small></figcaption></figure>@empty<p class="empty">画像はまだありません。</p>@endforelse</div>{{ $mediaItems->links() }}
@endsection

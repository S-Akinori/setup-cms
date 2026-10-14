@extends('layouts.app')
@section('title', '記事を作成')
@section('content')
<div class="page-head"><div><p class="eyebrow">NEW CONTENT</p><h1>記事を作成</h1></div></div>
<form method="post" action="{{ route('admin.posts.store') }}">@csrf @include('admin.posts.form')</form>
@endsection

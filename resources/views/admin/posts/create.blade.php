@extends('layouts.app')
@php($isPage = ($contentType ?? 'post') === 'page')
@section('title', $isPage ? '固定ページを作成' : '記事を作成')
@section('content')
<div class="page-head"><div><p class="eyebrow">NEW CONTENT</p><h1>{{ $isPage ? '固定ページを作成' : '記事を作成' }}</h1></div></div>
<form method="post" action="{{ route($isPage ? 'admin.pages.store' : 'admin.posts.store') }}">@csrf @include('admin.posts.form')</form>
@endsection

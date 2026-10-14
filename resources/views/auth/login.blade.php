@extends('layouts.app')
@section('title', 'ログイン')
@section('content')
<section class="auth-card">
    <p class="eyebrow">SECURE ACCESS</p><h1>管理画面へログイン</h1>
    <form method="post" action="/login" class="stack">@csrf
        <label>メールアドレス<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
        <label>パスワード<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="button primary" type="submit">続ける</button>
    </form>
</section>
@endsection

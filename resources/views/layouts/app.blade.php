<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CMS') — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @auth
        <header class="site-header">
            <a class="brand" href="{{ route('admin.posts.index') }}">{{ config('app.name') }}</a>
            <nav aria-label="管理メニュー">
                <a href="{{ route('admin.posts.index') }}">記事</a>
                <a href="{{ route('admin.media.index') }}">画像</a>
                <form action="{{ route('logout') }}" method="post">@csrf<button class="link-button">ログアウト</button></form>
            </nav>
        </header>
    @endauth
    <main class="container @guest auth-container @endguest">
        @if(session('success')) <div class="notice success">{{ session('success') }}</div> @endif
        @if(session('warning')) <div class="notice warning">{{ session('warning') }}</div> @endif
        @if($errors->any())
            <div class="notice error" role="alert"><strong>入力内容を確認してください。</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @yield('content')
    </main>
</body>
</html>

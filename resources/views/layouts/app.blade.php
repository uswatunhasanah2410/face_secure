<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sistem Keamanan')</title>

    <link rel="stylesheet" href="{{ asset('css/base.css') }}">
    <link rel="stylesheet" href="{{ asset('css/modal.css') }}">
    @stack('styles')
</head>
<body>
    @yield('content')

    <script src="{{ asset('js/common.js') }}"></script>
    @stack('scripts')
</body>
</html>

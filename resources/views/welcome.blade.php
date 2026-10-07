@extends('layouts.app')

@section('title', 'Selamat Datang')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
@endpush

@section('content')
<main class="landing">
    <span class="dots dots--tr" aria-hidden="true"></span>
    <span class="dots dots--bl" aria-hidden="true"></span>
    <span class="dots dots--wave" aria-hidden="true"></span>

    <nav class="landing__nav">
        @auth
            <a href="{{ route('home') }}" class="btn-pill">Beranda Saya</a>
        @else
            <a href="{{ route('login') }}" class="btn-pill">Login</a>
            <a href="{{ route('register') }}" class="btn-pill">Registrasi</a>
        @endauth
    </nav>

    <section class="landing__hero">
        <h1 class="title-serif text-grad landing__title">Selamat Datang!</h1>
        <p class="landing__tagline text-grad">Keamanan Anda, Prioritas Kami.</p>
        <p class="landing__desc">Akses sistem dengan mudah melalui proses autentikasi yang aman, cepat, dan terpercaya.</p>
        <a href="{{ auth()->check() ? route('home') : route('login') }}" class="landing__cta">
            <span class="text-grad">Mulai sekarang</span>
            <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </section>
</main>
@endsection

@extends('layouts.app')

@section('title', 'Beranda')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/home.css') }}">
@endpush

@section('content')
<main class="home">
    <span class="dots dots--tr" aria-hidden="true"></span>
    <span class="dots dots--bl" aria-hidden="true"></span>
    <span class="dots dots--wave" aria-hidden="true"></span>

    <nav class="home__nav">
        <button type="button" class="btn-pill" id="btn-profil">
            <svg class="icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7" /></svg>
            Profil
        </button>
    </nav>

    <header class="home__head">
        <h1 class="title-serif text-grad home__title">Selamat Datang Kembali!</h1>
        <p class="home__tagline text-grad">Akses Aman, Aktivitas Nyaman.</p>
        <p class="home__desc">
            Sistem ini dirancang untuk membantu menjaga keamanan data dan
            melindungi akses pengguna melalui proses autentikasi yang aman.
        </p>
    </header>

    <section class="home__body">
        <img class="home__art" src="{{ asset('images/asset3.png') }}" alt="Ilustrasi keamanan data">
        <button type="button" class="home__cta" id="btn-data">
            <span class="text-grad">Pengolahan Data</span>
            <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
        </button>
    </section>
</main>

{{-- Logout harus POST + CSRF, jadi lewat form tersembunyi --}}
<form method="POST" action="{{ route('logout') }}" id="form-logout" class="hidden">@csrf</form>
@endsection

@push('scripts')
    @php
        $user = auth()->user();
        $lastVerified = $user->faceSecure?->last_verified_at;
    @endphp
    <script>
        window.AppConfig = {
            user: {
                name:  @json($user->name),
                email: @json($user->email),
                loginAt: @json($lastVerified ? $lastVerified->timezone('Asia/Jakarta')->translatedFormat('d M Y, H:i') . ' WIB' : '-'),
            },
        };
    </script>
    <script src="{{ asset('js/home.js') }}"></script>
@endpush

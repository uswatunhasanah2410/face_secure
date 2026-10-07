{{-- Layout bersama halaman Registrasi & Login (panel kiri biru + panel kanan form) --}}
@extends('layouts.app')

@prepend('styles')
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
@endprepend

@section('content')
<div class="auth">
    {{-- Panel kiri --}}
    <aside class="auth__side">
        <span class="dots" aria-hidden="true"></span>
        <a href="{{ route('landing') }}" class="btn-pill auth__back">
            <svg class="icon" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6" /></svg>
            Beranda
        </a>
        <h1 class="title-serif auth__headline">@yield('headline')</h1>
        <img class="auth__art" src="@yield('art')" alt="@yield('art-alt', 'Ilustrasi')">
        <p class="auth__caption">
            @hasSection('caption')
                @yield('caption')
            @else
                Daftarkan diri Anda dan mulai<br>menggunakan layanan kami.
            @endif
        </p>
    </aside>

    {{-- Panel kanan --}}
    <main class="auth__main">
        <div class="auth__inner">
            @yield('panels')
        </div>
    </main>
</div>
@endsection

{{-- Library pengenalan wajah (lokal, bisa jalan offline). Halaman tanpa kamera: @section('no-camera', true) --}}
@unless (View::hasSection('no-camera'))
    @prepend('scripts')
        <script src="{{ asset('vendor/face-api/face-api.js') }}"></script>
        <script src="{{ asset('vendor/mediapipe/vision_bundle.js') }}"></script>
        <script src="{{ asset('js/face-guard.js') }}"></script>
    @endprepend
@endunless

@extends('layouts.auth')

@section('title', 'Login')
@section('headline') Masuk ke <span class="accent">Akun Anda!</span> @endsection
@section('art', asset('images/asset1.png'))
@section('art-alt', 'Ilustrasi login')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/login.css') }}">
@endpush

@section('panels')
    <ol class="stepper" id="stepper">
        <li class="step is-active">
            <span class="step__bar"></span><span class="step__label"><span class="step__num">1</span>Masuk akun</span>
        </li>
        <li class="step">
            <span class="step__bar"></span><span class="step__label"><span class="step__num">2</span>Verifikasi wajah</span>
        </li>
    </ol>

    {{-- STEP 1: Masuk akun --}}
    <section class="panel" id="panel-1">
        <h2 class="title-serif auth__title">Login</h2>
        <form id="form-login" novalidate>
            <div class="field" id="f-email">
                <label class="field__label" for="email">Email</label>
                <div class="field__box">
                    <svg class="icon" viewBox="0 0 24 24" width="24" height="24">
                        <rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 7l9 6 9-6" />
                    </svg>
                    <input id="email" type="email" placeholder="nama@email.com" autocomplete="email">
                </div>
                <p class="field__error"></p>
            </div>

            <div class="field" id="f-pass">
                <label class="field__label" for="pass">Password</label>
                <div class="field__box">
                    <svg class="icon" viewBox="0 0 24 24" width="24" height="24">
                        <rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
                    </svg>
                    <input id="pass" type="password" placeholder="Minimal 8 karakter" autocomplete="current-password">
                </div>
                <p class="field__error"></p>
            </div>

            <div class="forgot">
                <a href="{{ route('password.request') }}" class="link" id="btn-lupa">Lupa Password?</a>
            </div>

            <button type="submit" class="btn-primary" id="btn-login">
                Lanjut
                <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </button>
        </form>
        <p class="auth__foot">Belum punya akun? <a href="{{ route('register') }}" class="link">Daftar di sini</a></p>
    </section>

    {{-- STEP 2: Verifikasi wajah --}}
    <section class="panel hidden" id="panel-2">
        <h2 class="title-serif auth__title">Verifikasi Wajah</h2>
        <p class="auth__desc">Pastikan wajah Anda terlihat jelas di dalam kamera.</p>
        <div class="face" id="face">(Wajah)</div>
        <p class="face-status" id="face-status"></p>
        <ul class="tips">
            <li>Posisikan wajah di tengah area kamera</li>
            <li>Pastikan pencahayaan cukup dan wajah terlihat jelas.</li>
        </ul>
        <button type="button" class="btn-primary" id="btn-mulai-wajah">Mulai Verifikasi</button>
        <div class="auth__links">
            <button type="button" class="link link--back" id="btn-kembali">
                <svg class="icon" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6" /></svg>
                Kembali
            </button>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        window.AppConfig = {
            step: {{ $step }},
            modelsUrl: @json(asset('models')),
            routes: {
                login: @json(route('login.store')),
                face:  @json(route('login.face')),
            },
        };
    </script>
    <script src="{{ asset('js/login.js') }}"></script>
@endpush

@extends('layouts.auth')

@section('title', 'Lupa Password')
@section('headline') Lupa <span class="accent">Password?</span> @endsection
@section('art', asset('images/asset1.png'))
@section('art-alt', 'Ilustrasi lupa password')
@section('no-camera', true)
@section('caption') Atur ulang password Anda dengan<br>kode verifikasi dari email. @endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/register.css') }}">
    <link rel="stylesheet" href="{{ asset('css/login.css') }}">
@endpush

@section('panels')
    <ol class="stepper" id="stepper">
        <li class="step is-active">
            <span class="step__bar"></span><span class="step__label"><span class="step__num">1</span>Email</span>
        </li>
        <li class="step">
            <span class="step__bar"></span><span class="step__label"><span class="step__num">2</span>Kode verifikasi</span>
        </li>
        <li class="step">
            <span class="step__bar"></span><span class="step__label"><span class="step__num">3</span>Password baru</span>
        </li>
    </ol>

    {{-- STEP 1: Email --}}
    <section class="panel" id="panel-1">
        <h2 class="title-serif auth__title">Lupa Password</h2>
        <p class="auth__desc">
            Masukkan email akun Anda. Kami akan mengirim kode verifikasi<br>untuk mengatur ulang password.
        </p>
        <form id="form-email" novalidate>
            <div class="field" id="f-email">
                <label class="field__label" for="email">Email</label>
                <div class="field__box">
                    <svg class="icon" viewBox="0 0 24 24" width="24" height="24">
                        <rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 7l9 6 9-6" />
                    </svg>
                    <input id="email" type="email" placeholder="nama@email.com" autocomplete="email" maxlength="255">
                </div>
                <p class="field__error"></p>
            </div>

            <button type="submit" class="btn-primary" id="btn-kirim">
                Kirim kode
                <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </button>
        </form>
        <div class="auth__links">
            <a href="{{ route('login') }}" class="link link--back">
                <svg class="icon" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6" /></svg>
                Kembali ke Login
            </a>
        </div>
    </section>

    {{-- STEP 2: Kode verifikasi --}}
    <section class="panel hidden" id="panel-2">
        <h2 class="title-serif auth__title">Verifikasi Email</h2>
        <p class="auth__desc">
            Jika <span id="email-tujuan">nama@email.com</span> terdaftar, kode 6 digit sudah dikirim ke email tersebut.<br>
            Masukkan kodenya untuk melanjutkan.
        </p>

        <div class="notice">
            <svg class="icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" /><path d="M7.5 12.5l3 3 6-7" /></svg>
            <p>Cek kotak masuk email Anda.<br>Jangan lupa cek folder Spam.</p>
        </div>

        <p class="field__label otp-label">Kode verifikasi</p>
        <div class="otp" id="otp">
            @for ($i = 1; $i <= 6; $i++)
                <input type="text" inputmode="numeric" maxlength="1" aria-label="Digit {{ $i }}" @if ($i === 1) autocomplete="one-time-code" @endif>
            @endfor
        </div>
        <p class="field__error otp-error" id="otp-error"></p>
        <p class="auth__desc otp-note">Berlaku 10 menit. Tidak ada di kotak masuk?<br>Cek folder Spam atau Promosi.</p>

        <button type="button" class="btn-primary" id="btn-verif">
            Verifikasi kode
            <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
        </button>

        <div class="auth__links">
            <span class="muted">Tidak menerima kode?
                <button type="button" class="link" id="btn-kirim-ulang">Kirim ulang</button>
            </span>
            <button type="button" class="link link--back" id="btn-ubah-email">
                <svg class="icon" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6" /></svg>
                Salah email? Ubah email
            </button>
        </div>
    </section>

    {{-- STEP 3: Password baru --}}
    <section class="panel hidden" id="panel-3">
        <h2 class="title-serif auth__title">Password Baru</h2>
        <p class="auth__desc">Buat password baru untuk akun Anda. Gunakan minimal 8 karakter.</p>
        <form id="form-password" novalidate>
            <div class="field" id="f-pass">
                <label class="field__label" for="pass">Password baru</label>
                <div class="field__box">
                    <svg class="icon" viewBox="0 0 24 24" width="24" height="24">
                        <rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
                    </svg>
                    <input id="pass" type="password" placeholder="Minimal 8 karakter" autocomplete="new-password">
                </div>
                <p class="field__error"></p>
            </div>

            <div class="field" id="f-pass2">
                <label class="field__label" for="pass2">Ulangi password baru</label>
                <div class="field__box">
                    <svg class="icon" viewBox="0 0 24 24" width="24" height="24">
                        <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z" /><path d="M8.5 12l2.5 2.5 4.5-5" />
                    </svg>
                    <input id="pass2" type="password" placeholder="Ketik ulang password baru" autocomplete="new-password">
                </div>
                <p class="field__error"></p>
            </div>

            <button type="submit" class="btn-primary" id="btn-simpan">
                Simpan password baru
                <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </button>
        </form>
    </section>
@endsection

@push('scripts')
    <script>
        window.AppConfig = {
            step: {{ $step }},
            email: @json($email),
            routes: {
                send:   @json(route('password.email')),
                verify: @json(route('password.verify')),
                resend: @json(route('password.resend')),
                reset:  @json(route('password.update')),
            },
        };
    </script>
    <script src="{{ asset('js/forgot-password.js') }}"></script>
@endpush

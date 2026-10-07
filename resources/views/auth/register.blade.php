@extends('layouts.auth')

@section('title', 'Registrasi')
@section('headline') Buat Akun <span class="accent">Anda!</span> @endsection
@section('art', asset('images/asset2.png'))
@section('art-alt', 'Ilustrasi registrasi')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/register.css') }}">
@endpush

@section('panels')
    <ol class="stepper" id="stepper">
        <li class="step is-active">
            <span class="step__bar"></span><span class="step__label"><span class="step__num">1</span>Data akun</span>
        </li>
        <li class="step">
            <span class="step__bar"></span><span class="step__label"><span class="step__num">2</span>Verifikasi email</span>
        </li>
        <li class="step">
            <span class="step__bar"></span><span class="step__label"><span class="step__num">3</span>Verifikasi wajah</span>
        </li>
    </ol>

    {{-- STEP 1: Data akun --}}
    <section class="panel" id="panel-1">
        <h2 class="title-serif auth__title">Registrasi</h2>
        <form id="form-akun" novalidate>
            <div class="field" id="f-nama">
                <label class="field__label" for="nama">Nama lengkap</label>
                <div class="field__box">
                    <svg class="icon" viewBox="0 0 24 24" width="24" height="24">
                        <circle cx="12" cy="8" r="4" /><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7" />
                    </svg>
                    <input id="nama" type="text" placeholder="Nama lengkap Anda" autocomplete="name" maxlength="255">
                </div>
                <p class="field__error"></p>
            </div>

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

            <div class="field" id="f-pass">
                <label class="field__label" for="pass">Password</label>
                <div class="field__box">
                    <svg class="icon" viewBox="0 0 24 24" width="24" height="24">
                        <rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
                    </svg>
                    <input id="pass" type="password" placeholder="Minimal 8 karakter" autocomplete="new-password">
                </div>
                <p class="field__error"></p>
            </div>

            <div class="field" id="f-pass2">
                <label class="field__label" for="pass2">Ulangi password</label>
                <div class="field__box">
                    <svg class="icon" viewBox="0 0 24 24" width="24" height="24">
                        <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z" /><path d="M8.5 12l2.5 2.5 4.5-5" />
                    </svg>
                    <input id="pass2" type="password" placeholder="Ketik ulang password" autocomplete="new-password">
                </div>
                <p class="field__error"></p>
            </div>

            <button type="submit" class="btn-primary" id="btn-akun">
                Lanjut, kirim kode
                <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </button>
        </form>
        <p class="auth__foot">Sudah punya akun? <a href="{{ route('login') }}" class="link">Masuk</a></p>
    </section>

    {{-- STEP 2: Verifikasi email --}}
    <section class="panel hidden" id="panel-2">
        <h2 class="title-serif auth__title">Verifikasi Email</h2>
        <p class="auth__desc">
            Kode 6 digit sudah dikirim ke <span id="email-tujuan">nama@email.com</span>.<br>
            Masukkan kodenya untuk menyelesaikan pendaftaran.
        </p>

        <div class="notice" id="notice-kode">
            <svg class="icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" /><path d="M7.5 12.5l3 3 6-7" /></svg>
            <p>Kode verifikasi sudah dikirim ke email Anda.<br>Cek juga folder Spam.</p>
        </div>

        <p class="field__label otp-label">Kode verifikasi</p>
        <div class="otp" id="otp">
            @for ($i = 1; $i <= 6; $i++)
                <input type="text" inputmode="numeric" maxlength="1" aria-label="Digit {{ $i }}" @if ($i === 1) autocomplete="one-time-code" @endif>
            @endfor
        </div>
        <p class="field__error otp-error" id="otp-error"></p>
        <p class="auth__desc otp-note">Berlaku 10 menit. Tidak ada di kotak masuk?<br>Cek folder Spam atau Promosi.</p>

        <button type="button" class="btn-primary" id="btn-verif-email">
            Verifikasi dan buat akun
            <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
        </button>

        <div class="auth__links">
            <span class="muted">Tidak menerima kode?
                <button type="button" class="link" id="btn-kirim-ulang">Kirim ulang</button>
            </span>
            <button type="button" class="link link--back" id="btn-ubah-data">
                <svg class="icon" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6" /></svg>
                Salah email? Ubah data pendaftaran
            </button>
        </div>
    </section>

    {{-- STEP 3: Verifikasi wajah --}}
    <section class="panel hidden" id="panel-3">
        <h2 class="title-serif auth__title">Verifikasi Wajah</h2>
        <p class="auth__desc">Pastikan wajah Anda terlihat jelas untuk melakukan<br>verifikasi identitas.</p>
        <div class="face" id="face">(Wajah)</div>
        <p class="face-status" id="face-status"></p>
        <ol class="liveness" id="liveness"></ol>
        @if (request()->has('debug'))
            <pre class="face-debug" id="face-debug">Mode debug: metrik wajah tampil di sini.</pre>
        @endif
        <ul class="tips">
            <li>Pastikan seluruh wajah terlihat dan menghadap lurus ke kamera</li>
            <li>Buka mata, lepas masker/kacamata gelap, pencahayaan cukup</li>
            <li>Ikuti instruksi gerakan yang muncul (kedip, toleh, atau buka mulut)</li>
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
    @php
        // Disusun di sini: @json(...) Blade memecah argumen berdasarkan koma, jadi array panjang tidak boleh ditulis langsung di dalamnya
        $faceConfig = [
            'quality'         => config('face.quality'),
            'liveness'        => config('face.liveness'),
            'flash'           => config('face.flash'),
            'blocked_cameras' => config('face.blocked_cameras'),
        ];
    @endphp
    <script>
        window.AppConfig = {
            step: {{ $step }},
            draft: @json($draft),
            modelsUrl: @json(asset('models')),
            mediapipeWasm: @json(asset('vendor/mediapipe/wasm')),
            face: @json($faceConfig),
            routes: {
                store:  @json(route('register.store')),
                verify: @json(route('register.verify-email')),
                resend: @json(route('register.resend')),
                challenge: @json(route('register.face.challenge')),
                face:   @json(route('register.face')),
            },
        };
    </script>
    <script src="{{ asset('js/register.js') }}"></script>
@endpush

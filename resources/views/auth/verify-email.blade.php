@extends('layouts.auth')

@section('title', 'Registrasi - Verifikasi Email')
@section('left-title') Buat Akun <span>Anda!</span> @endsection
@section('left-image', asset('images/register-illustration.png'))

@php $email = session('register.email', 'nama@email.com'); @endphp

@section('form')
    <x-stepper :steps="['Data akun', 'Verifikasi email', 'Verifikasi wajah']" :current="2" />

    <h2 class="auth-title">Verifikasi Email</h2>

    <p class="auth-sub" style="padding: 0 20px">
        Kode 6 digit sudah dikirim ke {{ $email }}.<br>
        Masukkan kodenya untuk menyelesaikan pendaftaran.
    </p>

    @if (session('status'))
        <div class="alert-success">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="7 12.5 10.5 16 17 9"/></svg>
            <div>{{ session('status') }}<br>Cek juga folder Spam.</div>
        </div>
    @endif

    <form method="POST" action="{{ route('register.verify-email.store') }}" id="otp-form">
        @csrf
        <input type="hidden" name="code" id="otp-code">

        <div class="otp-label">Kode verifikasi</div>
        <div class="otp-boxes">
            @for ($i = 0; $i < 6; $i++)
                <input type="text" inputmode="numeric" maxlength="1" class="otp-input" autocomplete="one-time-code">
            @endfor
        </div>
        @error('code') <div class="error-text" style="margin: 8px 20px 0">{{ $message }}</div> @enderror

        <p class="otp-hint">Berlaku 10 menit. Tidak ada di kotak masuk?<br>Cek folder Spam atau Promosi.</p>

        <button type="submit" class="btn-primary">Verifikasi dan buat akun <span>&rarr;</span></button>
    </form>

    <form method="POST" action="{{ route('register.verify-email.resend') }}" id="resend-form">
        @csrf
        <p class="auth-foot">Tidak menerima kode?
            <a href="#" onclick="event.preventDefault(); document.getElementById('resend-form').submit()">Kirim ulang</a>
        </p>
    </form>

    <a href="{{ route('register') }}" class="link-back">&larr; Salah email? Ubah data pendaftaran</a>
@endsection

@push('scripts')
<script>
    const boxes = [...document.querySelectorAll('.otp-input')];
    const hidden = document.getElementById('otp-code');
    const sync = () => hidden.value = boxes.map(b => b.value).join('');

    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            box.value = box.value.replace(/\D/g, '');
            if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
            sync();
        });
        box.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus();
        });
        box.addEventListener('paste', e => {
            e.preventDefault();
            const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            digits.split('').forEach((d, j) => boxes[j] && (boxes[j].value = d));
            boxes[Math.min(digits.length, 5)].focus();
            sync();
        });
    });
    boxes[0].focus();

    document.getElementById('otp-form').addEventListener('submit', e => {
        sync();
        if (hidden.value.length !== 6) { e.preventDefault(); alert('Masukkan 6 digit kode verifikasi.'); }
    });
</script>
@endpush

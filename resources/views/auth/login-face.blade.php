@extends('layouts.auth')

@section('title', 'Login - Verifikasi Wajah')
@section('left-title') Masuk ke <span>Akun Anda!</span> @endsection
@section('left-image', asset('images/login-illustration.png'))

@php $result = session('face_result'); @endphp

@section('form')
    <x-stepper :steps="['Masuk akun', 'Verifikasi wajah']"
               :current="$result === 'success' ? 3 : 2"
               :failed="$result === 'failed'" />

    <h2 class="auth-title">Verifikasi Wajah</h2>

    <p class="auth-sub">Pastikan wajah Anda terlihat jelas di dalam kamera.</p>

    <form method="POST" action="{{ route('login.face.store') }}" id="face-form">
        @csrf
        <input type="hidden" name="face_descriptor">
        <input type="hidden" name="face_image">

        <x-face-camera form-id="face-form" />

        <p class="face-tips">
            Posisikan wajah di tengah area kamera<br>
            Pastikan pencahayaan cukup dan wajah terlihat jelas.
        </p>

        <button type="button" id="btn-start-face" class="btn-primary">Mulai Verifikasi</button>
    </form>

    <a href="{{ route('login') }}" class="link-back">&larr; Kembali</a>
@endsection

@section('modal')
    <x-result-modal id="modal-success" type="success" title="Login Berhasil"
        message="Identitas Anda berhasil diverifikasi.<br>Selamat datang kembali!"
        button="Masuk ke Beranda" :href="route('home')" :show="$result === 'success'" />

    <x-result-modal id="modal-failed" type="failed" title="Login Gagal"
        :message="session('face_error') ? e(session('face_error')) : 'Verifikasi wajah tidak berhasil. Silakan pastikan posisi<br>dan pencahayaan wajah sudah sesuai.'"
        button="Coba Lagi" :show="$result === 'failed'" />
@endsection

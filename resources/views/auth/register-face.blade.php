@extends('layouts.auth')

@section('title', 'Registrasi - Verifikasi Wajah')
@section('left-title') Buat Akun <span>Anda!</span> @endsection
@section('left-image', asset('images/register-illustration.png'))

@php
    // Diisi dari controller via ->with('face_result', 'success'|'failed')
    $result = session('face_result');
@endphp

@section('form')
    <x-stepper :steps="['Data akun', 'Verifikasi email', 'Verifikasi wajah']"
               :current="$result === 'success' ? 4 : 3"
               :failed="$result === 'failed'" />

    <h2 class="auth-title">Verifikasi Wajah</h2>

    <p class="auth-sub">Pastikan wajah Anda terlihat jelas untuk melakukan verifikasi identitas.</p>

    <form method="POST" action="{{ route('register.face.store') }}" id="face-form">
        @csrf
        <input type="hidden" name="face_descriptor">
        <input type="hidden" name="face_image">

        <x-face-camera form-id="face-form" />

        <p class="face-tips">
            Cari tempat dengan pencahayaan cukup<br>
            Pastikan wajah terlihat jelas<br>
            Tatap kamera secara langsung
        </p>

        <button type="button" id="btn-start-face" class="btn-primary">Mulai Verifikasi</button>
    </form>

    <a href="{{ route('register.verify-email') }}" class="link-back">&larr; Kembali</a>
@endsection

@section('modal')
    <x-result-modal id="modal-success" type="success" title="Registrasi Berhasil"
        message="Akun Anda berhasil dibuat. Silakan login untuk melanjutkan."
        button="Login Sekarang" :href="route('login')" :show="$result === 'success'" />

    <x-result-modal id="modal-failed" type="failed" title="Registrasi Gagal"
        :message="session('face_error') ? e(session('face_error')) : 'Data yang dimasukkan tidak valid atau terjadi<br>kesalahan pada sistem. Silakan coba kembali.'"
        button="Coba Lagi" :show="$result === 'failed'" />
@endsection

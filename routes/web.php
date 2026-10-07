<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('landing');

// ---------- Tamu (belum login) ----------
// Halaman register & login masing-masing 1 halaman; langkah-langkahnya dikirim lewat fetch (JSON).
Route::middleware('guest')->group(function () {
    // Registrasi
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:register')->name('register.store');
    Route::post('/register/verify-email', [RegisterController::class, 'verifyEmail'])
        ->middleware('throttle:otp-verify')->name('register.verify-email');
    Route::post('/register/resend-code', [RegisterController::class, 'resendOtp'])
        ->middleware('throttle:otp-resend')->name('register.resend');
    Route::post('/register/face', [RegisterController::class, 'storeFace'])
        ->middleware('throttle:face')->name('register.face');

    // Login
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::post('/login/face', [LoginController::class, 'verifyFace'])
        ->middleware('throttle:face')->name('login.face');

    // Lupa password
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::middleware('throttle:password-reset')->group(function () {
        Route::post('/forgot-password', [ForgotPasswordController::class, 'sendCode'])->name('password.email');
        Route::post('/forgot-password/verify', [ForgotPasswordController::class, 'verifyCode'])->name('password.verify');
        Route::post('/forgot-password/resend', [ForgotPasswordController::class, 'resend'])->name('password.resend');
        Route::post('/forgot-password/reset', [ForgotPasswordController::class, 'resetPassword'])->name('password.update');
    });
});

// ---------- Sudah login ----------
Route::middleware('auth')->group(function () {
    Route::view('/home', 'home')->name('home');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});

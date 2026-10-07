<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi verifikasi wajah:
 * - face_descriptor sekarang menyimpan BEBERAPA sampel (array of 128 angka), tetap terenkripsi.
 * - eye_baseline   : skor mata (eyeBlink) normal saat registrasi, dipakai untuk cek kondisi mata saat login.
 * - samples_count  : jumlah sampel tersimpan (informasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_secures', function (Blueprint $table) {
            $table->decimal('eye_baseline', 6, 4)->nullable()->after('face_descriptor');
            $table->unsignedTinyInteger('samples_count')->default(1)->after('eye_baseline');
        });
    }

    public function down(): void
    {
        Schema::table('face_secures', function (Blueprint $table) {
            $table->dropColumn(['eye_baseline', 'samples_count']);
        });
    }
};

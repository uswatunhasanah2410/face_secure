<?php

/*
|--------------------------------------------------------------------------
| Konfigurasi verifikasi wajah (face recognition + liveness)
|--------------------------------------------------------------------------
| Semua angka di sini dipakai di DUA tempat:
|   - browser (public/js/face-guard.js) untuk memandu pengguna, dan
|   - server (App\Services\FaceLivenessValidator) untuk memvalidasi ulang.
| Nilai default dikalibrasi dengan foto wajah asli (lihat catatan revisi).
| Untuk melihat nilai metrik wajah Anda sendiri: buka halaman login/register dengan ?debug=1
*/

return [

    // ---------- Pencocokan wajah (revisi 1) ----------
    'match' => [
        // Jarak Euclidean descriptor maksimum agar dianggap orang yang sama saat login.
        // Kalibrasi: orang sama <= 0.28, orang berbeda >= 0.445 (0.45 masih meloloskan 1 dari 361 pasangan).
        'threshold'   => (float) env('FACE_MATCH_THRESHOLD', 0.40),
        // Sampel-sampel dalam 1 sesi harus saling mirip (memastikan orangnya tidak berganti di tengah proses).
        'consistency' => (float) env('FACE_CONSISTENCY_THRESHOLD', 0.38),
        // Wajah yang sudah dipakai akun lain ditolak saat registrasi.
        'duplicate'   => (float) env('FACE_DUPLICATE_THRESHOLD', 0.40),
        // Jumlah sampel tersimpan terdekat yang dirata-rata untuk tiap sampel login.
        'nearest_k'   => 3,
    ],

    // Jumlah sampel descriptor yang diambil (sebelum & sesudah tantangan liveness)
    'samples' => [
        'register' => ['before' => 3, 'after' => 2],
        'login'    => ['before' => 2, 'after' => 1],
    ],

    // ---------- Kualitas wajah: utuh, lurus, mata terbuka (revisi 3, 4, 5) ----------
    'quality' => [
        'min_score'          => 0.80,  // skor detektor SSD minimal (wajah bermasker/tertutup: 0.57-0.78)
        'margin'             => 0.02,  // semua 478 titik wajah minimal 2% dari tepi frame
        'min_width'          => 0.22,  // lebar wajah minimal (proporsi lebar frame)
        'max_width'          => 0.75,
        'max_center'         => 0.22,  // wajah harus dekat tengah frame
        'max_yaw'            => 12,    // derajat menoleh maksimal (wajah lurus 4-7 derajat)
        'max_roll'           => 12,    // derajat kepala miring maksimal
        'pitch_min'          => -15,   // derajat mendongak maksimal
        'pitch_max'          => 25,    // derajat menunduk maksimal (kamera laptop biasanya di bawah mata)
        'cover_max'          => 15,    // wajah tertutup masker: beda warna hidung/bibir/dagu vs dahi (normal <= 12.2, masker >= 17)
        'eye_open_max'       => 0.40,  // skor eyeBlink rata-rata maksimal agar mata dianggap terbuka (terbuka ~0.05)
        'stable_frames'      => 8,     // jumlah frame berturut-turut yang harus lolos sebelum sampel diambil
        'sample_interval_ms' => 200,
    ],

    // ---------- Liveness / anti foto (revisi 2) ----------
    'liveness' => [
        // "blink" selalu ada. Ditambah sejumlah tantangan acak dari daftar ini, urutan diacak.
        'extra_pool'      => ['turn_left', 'turn_right', 'open_mouth'],
        'extra_steps'     => (int) env('FACE_LIVENESS_EXTRA_STEPS', 2),   // total 3 tantangan
        'blink_closed'    => 0.45,   // skor eyeBlink saat mata terpejam (mata terbuka ~0.05)
        'blink_delta'     => 0.30,   // dan naik minimal 0.30 dari kondisi normal pengguna
        'blink_reopen'    => 0.15,   // dianggap terbuka lagi jika <= normal + 0.15
        'turn_deg'        => 20,     // menoleh minimal 20 derajat
        'mouth_open'      => 0.40,   // skor jawOpen mulut terbuka
        'mouth_closed'    => 0.15,
        'step_timeout'    => 7,      // detik per tantangan
        // Anti video rekaman: gerakan harus terjadi SETELAH instruksi muncul (reaksi manusia), bukan sebelumnya
        'react_min_ms'    => 300,
        'react_max_ms'    => 6000,
        // Jeda acak sebelum instruksi berikutnya muncul (ms), supaya waktunya tidak bisa ditebak video
        'gap_min_ms'      => 500,
        'gap_max_ms'      => 1400,
        'total_timeout'   => 90,     // detik untuk seluruh proses
        'max_lost_ms'     => 1500,   // wajah boleh hilang sesaat maksimal 1,5 detik
        'max_jump'        => 0.25,   // perpindahan posisi wajah antar frame maksimal (proporsi frame)
        'challenge_ttl'   => 120,    // detik masa berlaku tantangan dari server
        'min_duration_ms' => 1500,   // proses yang terlalu cepat dianggap tidak wajar (otomatisasi)
    ],

    // ---------- Anti video di layar HP: pantulan warna layar (revisi lanjutan) ----------
    // Layar laptop berkedip warna acak. Wajah asli memantulkan warna itu; wajah yang ditampilkan
    // di layar HP memancarkan cahayanya sendiri sehingga warnanya tidak ikut berubah.
    // Lihat nilai corr & amp Anda dengan ?debug=1 lalu sesuaikan bila perlu.
    'flash' => [
        'enabled'  => (bool) env('FACE_FLASH_ENABLED', true),
        'count'    => 6,       // jumlah kedipan warna
        'epoch_ms' => 450,     // lama tiap warna
        'skip_ms'  => 150,     // abaikan awal tiap warna (jeda kamera menyesuaikan)
        'min_corr' => (float) env('FACE_FLASH_MIN_CORR', 0.5),     // kecocokan pola warna (-1..1)
        'min_amp'  => (float) env('FACE_FLASH_MIN_AMP', 0.0015),   // besar perubahan warna kulit
    ],

    // Kamera virtual (aplikasi yang bisa memutar video sebagai kamera) ditolak
    'blocked_cameras' => 'obs|virtual|manycam|xsplit|snap camera|camtwist|e2esoft|vcam|splitcam|youcam|droidcam|iriun|epoccam',

    // Batas percobaan verifikasi wajah saat login sebelum akun dikunci sementara
    'lockout' => [
        'max_attempts' => 5,
        'minutes'      => 15,
    ],
];

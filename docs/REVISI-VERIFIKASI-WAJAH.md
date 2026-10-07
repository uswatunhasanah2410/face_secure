# Revisi Verifikasi Wajah

Sistem memakai dua model yang saling melengkapi, keduanya berjalan di browser:

| Model | Dipakai untuk |
|---|---|
| **MediaPipe Face Landmarker** (478 titik wajah + blendshape + matriks rotasi kepala) | jumlah wajah, keutuhan wajah, sudut kepala, kondisi mata, gerakan liveness |
| **face-api.js** (SSD MobileNet + FaceRecognitionNet) | skor deteksi wajah dan *descriptor* 128 angka untuk mengenali identitas |

Semua batas (threshold) ada di `config/face.php` dan **divalidasi ulang di server** (`app/Services/FaceLivenessValidator.php`).
Untuk melihat nilai metrik secara langsung, buka halaman login/register dengan `?debug=1`.

## 1. Akurasi pengenalan wajah

- Detektor diganti dari Tiny Face Detector ke **SSD MobileNet** (lebih akurat).
- Saat registrasi disimpan **5 sampel wajah** (bukan 1 rata-rata), terenkripsi di database.
- Saat login diambil 3 sampel, dan **setiap** sampel harus cocok (rata-rata jarak ke 3 sampel tersimpan terdekat < **0,40**). Satu sampel gagal → ditolak.
- Semua sampel dalam satu sesi harus saling mirip (< 0,38). Kalau orangnya berganti di tengah proses → ditolak.
- **Kalibrasi** dengan 20 wajah asli: jarak orang yang sama ≤ 0,28, orang berbeda ≥ 0,445. Threshold lama 0,45 masih meloloskan 1 dari 361 pasangan orang berbeda; dengan 0,40 tidak ada yang lolos.

## 2. Liveness (anti foto)

- Server membuat **tantangan acak**: wajib *kedip* + 1 gerakan acak (toleh kiri / toleh kanan / buka mulut), dengan urutan acak.
- Tantangan **sekali pakai** (nonce), punya **batas waktu**, dan proses yang terlalu cepat (< 1,5 detik) ditolak.
- Kedip dideteksi dari skor `eyeBlink` MediaPipe (terbuka ±0,05 → terpejam ≥ 0,45). **Foto tidak bisa berkedip** → tantangan gagal.
- Selama tantangan, wajah dilacak terus: harus tepat 1 wajah dan tidak boleh "melompat" posisi.

## 3. Wajah harus utuh

- Seluruh **478 titik wajah** dan kotak wajah harus berada di dalam frame (margin 2%).
- Skor deteksi SSD minimal 0,80.
- **Deteksi masker/penutup**: warna (kroma) ujung hidung, bibir, dan dagu dibandingkan dengan warna kulit dahi. Kalau ketiganya berbeda jauh (≥ 15) → wajah tertutup. Hasil kalibrasi: masker terdeteksi 20/20, salah deteksi pada wajah normal 0/40.

## 4. Sudut wajah

Dihitung dari matriks rotasi kepala MediaPipe (derajat). Sampel hanya diambil jika:
yaw (menoleh) ≤ 12°, roll (miring) ≤ 12°, pitch (menunduk/mendongak) −15° s/d +25°.

## 5. Kondisi mata

- Mata dianggap terbuka jika skor `eyeBlink` rata-rata ≤ 0,40. Sampel dengan mata tertutup ditolak.
- Saat login, kondisi mata dibandingkan dengan kondisi saat registrasi (`eye_baseline`).

## Hasil pengujian

- **Tes otomatis** (`php artisan test`): 19 tes / 228 assertion, mencakup kelima poin revisi.
- **Uji browser** dengan kamera palsu (video dari wajah asli):

| Skenario | Hasil |
|---|---|
| Pemilik akun, hidup & berkedip | ✅ Berhasil (jarak 0,09) |
| Foto pemilik akun (tidak berkedip) | ❌ Ditolak: tantangan kedip tidak terdeteksi |
| Orang lain yang hidup & berkedip | ❌ Ditolak: wajah tidak cocok (jarak 0,77) |
| Wajah terpotong | ❌ "Wajah tidak utuh" |
| Kepala miring 25° | ❌ "Kepala miring" |
| Wajah menghadap samping (28°) | ❌ "Wajah menghadap ke samping" |
| Mata terpejam | ❌ "Mata terdeteksi tertutup" |
| Memakai masker | ❌ "Wajah tertutup (masker/kain)" |

## Keterbatasan (jujur untuk laporan)

- Pemrosesan wajah berjalan di **browser**. Penyerang yang paham teknis bisa memalsukan data yang dikirim ke server. Server memvalidasi ulang semua nilai, tapi tidak bisa melihat videonya. Peningkatan berikutnya: proses pengenalan wajah dipindah ke server.
- Liveness berbasis gerakan efektif terhadap **foto**, tapi video rekaman yang kebetulan berisi gerakan yang sama tetap berisiko. Urutan acak dan batas waktu memperkecil peluang ini.
- Wajah yang ditutup **tangan** (warnanya sama dengan kulit) tidak selalu terdeteksi oleh cek warna. Biasanya tetap gagal di tahap pencocokan wajah.

## Tambahan: anti video yang diputar di layar HP

Masalah: video wajah pemilik akun yang diputar di HP lalu diarahkan ke webcam masih bisa lolos, karena video itu berisi kedipan dan gerakan asli.

| Lapisan | Cara kerja | Hasil uji |
|---|---|---|
| **Pantulan warna layar** | Layar laptop berkedip 6 warna acak (merah/hijau/biru) dari server. Warna kulit wajah diukur tiap frame. Wajah asli memantulkan warna itu; layar HP memancarkan cahayanya sendiri sehingga warnanya tidak ikut berubah. Syarat: korelasi pola ≥ 0,5 dan besar perubahan ≥ 0,0015 | Video diputar sebagai kamera: korelasi −0,12, perubahan 0,0006 → **ditolak**. Simulasi wajah asli: korelasi 0,88, perubahan 0,0147 → lolos |
| **Tantangan anti-rekaman** | 3 gerakan acak (kedip wajib + 2 dari toleh kiri/kanan/buka mulut), urutan acak, ada jeda acak sebelum tiap instruksi. Gerakan harus dimulai 0,3–6 detik **setelah** instruksi muncul. Gerakan lain yang tidak diminta langsung gagal | Video yang berisi "semua gerakan" atau bergerak sebelum instruksi → ditolak (unit test) |
| **Blokir kamera virtual** | Kamera bernama OBS, ManyCam, DroidCam, Iriun, dll. ditolak di browser dan server | "OBS Virtual Camera" → **ditolak** |

Model anti-spoofing siap pakai (antispoof dari library Human) sempat diuji, tetapi tidak bisa membedakan wajah asli (skor tengah 0,67) dan wajah dari layar (0,66–0,71), sehingga tidak dipakai.

Keterbatasan: cek pantulan paling andal di ruangan normal/redup dengan kecerahan layar tinggi. Di bawah sinar matahari langsung pantulannya lemah. Nilai korelasi dan perubahan warna bisa dilihat dengan `?debug=1` (console browser) dan batasnya diatur di `config/face.php` bagian `flash`.

Unit test logika anti-video: `node --test tests/js/face-guard.test.cjs` (13 tes).

/* Logika halaman Registrasi — terhubung ke backend Laravel */
(() => {
  const C = window.AppConfig;
  const stepper = document.getElementById('stepper');
  const panels = [1, 2, 3].map(n => document.getElementById('panel-' + n));
  const f = id => document.getElementById(id);
  const faceEl = f('face');

  function goTo(n) {
    panels.forEach((p, i) => p.classList.toggle('hidden', i + 1 !== n));
    App.setStep(stepper, n);
    if (n !== 3) FaceGuard.stop(faceEl);
    if (n === 2) otp.focus();
  }

  // Sesi pendaftaran di server habis -> kembali ke langkah 1
  function restart(message) {
    App.showModal({
      type: 'error',
      title: 'Sesi Berakhir',
      message: App.esc(message || 'Sesi pendaftaran habis. Silakan isi data kembali.'),
      button: 'Isi Ulang Data',
      onClose: () => goTo(1)
    });
    goTo(1);
  }

  /* ---------- Step 1: Data akun ---------- */
  const fieldMap = { name: 'f-nama', email: 'f-email', password: 'f-pass', password_confirmation: 'f-pass2' };

  f('form-akun').addEventListener('submit', async e => {
    e.preventDefault();
    const nama = f('nama').value.trim();
    const email = f('email').value.trim();
    const pass = f('pass').value;
    const pass2 = f('pass2').value;

    // Validasi cepat di browser (server tetap memvalidasi ulang)
    const errs = {
      name: nama.length < 3 ? 'Nama lengkap minimal 3 karakter.' : '',
      email: !App.isEmail(email) ? 'Format email belum valid.' : '',
      password: pass.length < 8 ? 'Password minimal 8 karakter.' : '',
      password_confirmation: pass2 !== pass || !pass2 ? 'Password tidak sama.' : ''
    };
    Object.entries(fieldMap).forEach(([k, id]) => App.setFieldError(f(id), errs[k]));
    if (Object.values(errs).some(Boolean)) return;

    const btn = f('btn-akun');
    App.setLoading(btn, true, 'Mengirim kode...');
    const r = await App.api(C.routes.store, { name: nama, email, password: pass, password_confirmation: pass2 });
    App.setLoading(btn, false);

    if (!r.ok) {
      if (r.data.errors) {
        Object.entries(fieldMap).forEach(([k, id]) => App.setFieldError(f(id), App.fieldError(r, k)));
      } else {
        App.showModal({ type: 'error', title: 'Gagal Mengirim Kode', message: App.esc(r.data.message), button: 'Tutup' });
      }
      return;
    }

    f('email-tujuan').textContent = r.data.email;
    f('pass').value = f('pass2').value = '';
    otp.clear();
    App.toast(r.data.message);
    goTo(2);
  });

  /* ---------- Step 2: OTP ---------- */
  const otp = App.otpInput(f('otp'), f('otp-error'), () => f('btn-verif-email').click());

  f('btn-verif-email').addEventListener('click', async () => {
    const code = otp.value();
    if (code.length < 6) return otp.setError('Masukkan 6 digit kode verifikasi.');

    const btn = f('btn-verif-email');
    App.setLoading(btn, true, 'Memeriksa kode...');
    const r = await App.api(C.routes.verify, { code });
    App.setLoading(btn, false);

    if (!r.ok) {
      if (r.data.restart) return restart(r.data.message);
      return otp.setError(App.fieldError(r, 'code') || r.data.message);
    }
    App.toast('Email berhasil diverifikasi');
    goTo(3);
  });

  f('btn-kirim-ulang').addEventListener('click', async e => {
    const btn = e.currentTarget;
    btn.disabled = true;
    const r = await App.api(C.routes.resend);
    btn.disabled = false;
    if (r.data.restart) return restart(r.data.message);
    if (r.ok) otp.clear();
    App.toast(r.data.message);
  });

  f('btn-ubah-data').addEventListener('click', () => goTo(1));

  /* ---------- Step 3: Wajah ---------- */
  f('btn-kembali').addEventListener('click', () => goTo(2));

  const faceFail = message => {
    App.setStep(stepper, 3, 'error');
    App.showModal({
      type: 'error',
      title: 'Registrasi Gagal',
      message: App.esc(message),
      button: 'Coba Lagi',
      onClose: () => App.setStep(stepper, 3)
    });
  };

  App.bindFaceButton({
    btn: f('btn-mulai-wajah'),
    faceEl,
    statusEl: f('face-status'),
    stepsEl: f('liveness'),
    challengeUrl: C.routes.challenge,
    onRestart: restart,
    onFail: faceFail,
    submit: async payload => {
      App.setStep(stepper, 3);
      const r = await App.api(C.routes.face, payload);

      if (r.ok) {
        App.markAllDone(stepper);
        App.showModal({
          type: 'success',
          title: 'Registrasi Berhasil',
          message: 'Akun Anda berhasil dibuat. Silakan login untuk melanjutkan.',
          button: 'Login Sekarang',
          onClose: () => (location.href = r.data.redirect)
        });
        return;
      }
      if (r.data.restart) return restart(r.data.message);

      faceFail(r.data.message);
    }
  });

  /* ---------- Posisi awal (kalau halaman di-refresh di tengah alur) ---------- */
  f('nama').value = C.draft.name || '';
  f('email').value = C.draft.email || '';
  if (C.draft.email) f('email-tujuan').textContent = C.draft.email;
  goTo(C.step);
})();

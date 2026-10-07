/* Logika halaman Login — terhubung ke backend Laravel */
(() => {
  const C = window.AppConfig;
  const stepper = document.getElementById('stepper');
  const panels = [1, 2].map(n => document.getElementById('panel-' + n));
  const f = id => document.getElementById(id);
  const faceEl = f('face');

  function goTo(n) {
    panels.forEach((p, i) => p.classList.toggle('hidden', i + 1 !== n));
    App.setStep(stepper, n);
    if (n !== 2) App.Face.stop(faceEl);
  }

  // Sesi login habis / akun dikunci -> kembali ke langkah 1 dengan pesan dari server
  function restart(message) {
    App.showModal({
      type: 'error',
      title: 'Login Gagal',
      message: App.esc(message),
      button: 'Kembali',
      onClose: () => goTo(1)
    });
    goTo(1);
  }

  /* ---------- Step 1: Email & password ---------- */
  f('form-login').addEventListener('submit', async e => {
    e.preventDefault();
    const email = f('email').value.trim();
    const pass = f('pass').value;

    const errEmail = !App.isEmail(email) ? 'Format email belum valid.' : '';
    const errPass = !pass ? 'Password wajib diisi.' : '';
    App.setFieldError(f('f-email'), errEmail);
    App.setFieldError(f('f-pass'), errPass);
    if (errEmail || errPass) return;

    const btn = f('btn-login');
    App.setLoading(btn, true, 'Memeriksa akun...');
    const r = await App.api(C.routes.login, { email, password: pass });
    App.setLoading(btn, false);

    if (!r.ok) {
      App.setFieldError(f('f-email'), App.fieldError(r, 'email') || r.data.message);
      App.setFieldError(f('f-pass'), App.fieldError(r, 'password'));
      return;
    }

    f('pass').value = '';
    App.toast('Akun ditemukan, lanjut verifikasi wajah');
    goTo(2);
  });

  /* ---------- Step 2: Verifikasi wajah ---------- */
  f('btn-kembali').addEventListener('click', () => goTo(1));

  App.bindFaceButton({
    btn: f('btn-mulai-wajah'),
    faceEl,
    statusEl: f('face-status'),
    submit: async ({ descriptor }) => {
      App.setStep(stepper, 2);
      const r = await App.api(C.routes.face, { face_descriptor: JSON.stringify(descriptor) });

      if (r.ok) {
        App.markAllDone(stepper);
        App.showModal({
          type: 'success',
          title: 'Login Berhasil',
          message: 'Identitas Anda berhasil diverifikasi.<br>Selamat datang kembali!',
          button: 'Masuk ke Beranda',
          onClose: () => (location.href = r.data.redirect)
        });
        return;
      }
      if (r.data.restart) return restart(r.data.message);

      App.setStep(stepper, 2, 'error');
      App.showModal({
        type: 'error',
        title: 'Login Gagal',
        message: App.esc(r.data.message),
        button: 'Coba Lagi',
        onClose: () => App.setStep(stepper, 2)
      });
    }
  });

  goTo(C.step);
})();

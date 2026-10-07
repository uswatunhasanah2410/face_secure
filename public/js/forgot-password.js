/* Logika halaman Lupa Password — terhubung ke backend Laravel */
(() => {
  const C = window.AppConfig;
  const stepper = document.getElementById('stepper');
  const panels = [1, 2, 3].map(n => document.getElementById('panel-' + n));
  const f = id => document.getElementById(id);

  function goTo(n) {
    panels.forEach((p, i) => p.classList.toggle('hidden', i + 1 !== n));
    App.setStep(stepper, n);
    if (n === 1) f('email').focus();
    if (n === 2) otp.focus();
    if (n === 3) f('pass').focus();
  }

  function restart(message) {
    App.showModal({
      type: 'error',
      title: 'Sesi Berakhir',
      message: App.esc(message || 'Sesi reset password habis. Silakan masukkan email kembali.'),
      button: 'Masukkan Email',
      onClose: () => goTo(1)
    });
    goTo(1);
  }

  /* ---------- Step 1: Email ---------- */
  f('form-email').addEventListener('submit', async e => {
    e.preventDefault();
    const email = f('email').value.trim();
    const err = !App.isEmail(email) ? 'Format email belum valid.' : '';
    App.setFieldError(f('f-email'), err);
    if (err) return;

    const btn = f('btn-kirim');
    App.setLoading(btn, true, 'Mengirim kode...');
    const r = await App.api(C.routes.send, { email });
    App.setLoading(btn, false);

    if (!r.ok) {
      if (r.data.errors) return App.setFieldError(f('f-email'), App.fieldError(r, 'email'));
      return App.showModal({ type: 'error', title: 'Gagal Mengirim Kode', message: App.esc(r.data.message), button: 'Tutup' });
    }

    f('email-tujuan').textContent = r.data.email;
    otp.clear();
    App.toast(r.data.message);
    goTo(2);
  });

  /* ---------- Step 2: Kode verifikasi ---------- */
  const otp = App.otpInput(f('otp'), f('otp-error'), () => f('btn-verif').click());

  f('btn-verif').addEventListener('click', async () => {
    const code = otp.value();
    if (code.length < 6) return otp.setError('Masukkan 6 digit kode verifikasi.');

    const btn = f('btn-verif');
    App.setLoading(btn, true, 'Memeriksa kode...');
    const r = await App.api(C.routes.verify, { code });
    App.setLoading(btn, false);

    if (!r.ok) {
      if (r.data.restart) return restart(r.data.message);
      return otp.setError(App.fieldError(r, 'code') || r.data.message);
    }
    App.toast('Kode benar, silakan buat password baru');
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

  f('btn-ubah-email').addEventListener('click', () => goTo(1));

  /* ---------- Step 3: Password baru ---------- */
  f('form-password').addEventListener('submit', async e => {
    e.preventDefault();
    const pass = f('pass').value;
    const pass2 = f('pass2').value;

    const errPass = pass.length < 8 ? 'Password minimal 8 karakter.' : '';
    const errPass2 = !pass2 || pass2 !== pass ? 'Password tidak sama.' : '';
    App.setFieldError(f('f-pass'), errPass);
    App.setFieldError(f('f-pass2'), errPass2);
    if (errPass || errPass2) return;

    const btn = f('btn-simpan');
    App.setLoading(btn, true, 'Menyimpan...');
    const r = await App.api(C.routes.reset, { password: pass, password_confirmation: pass2 });
    App.setLoading(btn, false);

    if (!r.ok) {
      if (r.data.restart) return restart(r.data.message);
      if (r.data.errors) {
        App.setFieldError(f('f-pass'), App.fieldError(r, 'password'));
        return;
      }
      return App.showModal({ type: 'error', title: 'Gagal Menyimpan', message: App.esc(r.data.message), button: 'Coba Lagi' });
    }

    f('pass').value = f('pass2').value = '';
    App.markAllDone(stepper);
    App.showModal({
      type: 'success',
      title: 'Password Berhasil Diubah',
      message: 'Password Anda sudah diperbarui.<br>Silakan login dengan password baru.',
      button: 'Login Sekarang',
      onClose: () => (location.href = r.data.redirect)
    });
  });

  /* ---------- Posisi awal (kalau halaman di-refresh di tengah alur) ---------- */
  f('email').value = C.email || '';
  if (C.email) f('email-tujuan').textContent = C.email;
  goTo(C.step);
})();

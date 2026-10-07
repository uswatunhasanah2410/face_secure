/* Logika halaman Beranda setelah login */
(() => {
  const user = window.AppConfig.user;

  document.getElementById('btn-profil').addEventListener('click', () => {
    App.showModal({
      type: 'info',
      title: 'Profil Pengguna',
      message: `<b>${App.esc(user.name)}</b><br>${App.esc(user.email)}<br><small>Verifikasi wajah terakhir: ${App.esc(user.loginAt)}</small>`,
      button: 'Keluar',
      onClose: () => document.getElementById('form-logout').submit()
    });
  });

  document.getElementById('btn-data').addEventListener('click', () => {
    App.showModal({
      type: 'info',
      title: 'Pengolahan Data',
      message: 'Halaman pengolahan data belum tersedia dan akan dihubungkan ke sistem sebenarnya.',
      button: 'Mengerti'
    });
  });
})();

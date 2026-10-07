/* Helper umum: modal, toast, stepper, request ke server, dan kamera wajah (face-api.js) */
const App = (() => {
  const ICONS = {
    success: '<svg class="icon" viewBox="0 0 24 24"><path d="M4 12.5l5 5L20 6.5"/></svg>',
    error: '<svg class="icon" viewBox="0 0 24 24"><path d="M5 5l14 14M19 5L5 19"/></svg>',
    info: '<svg class="icon" viewBox="0 0 24 24"><path d="M12 8h.01M11 12h1v5h1"/></svg>'
  };

  // Escape teks sebelum dimasukkan ke innerHTML (cegah XSS)
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function showModal({ type = 'info', title, message, button = 'OK', onClose }) {
    document.querySelectorAll('.modal-overlay').forEach(el => el.remove());
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
      <div class="modal modal--${type}" role="dialog" aria-modal="true">
        <button class="modal__close" aria-label="Tutup">&times;</button>
        <h2 class="modal__title">${title}</h2>
        <div class="modal__icon">${ICONS[type] || ICONS.info}</div>
        <p class="modal__text">${message}</p>
        <button class="modal__btn">${button}</button>
      </div>`;
    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('is-open'));

    const close = (runCallback) => {
      overlay.classList.remove('is-open');
      setTimeout(() => overlay.remove(), 200);
      if (runCallback && onClose) onClose();
    };
    overlay.querySelector('.modal__btn').addEventListener('click', () => close(true));
    overlay.querySelector('.modal__close').addEventListener('click', () => close(false));
    overlay.addEventListener('click', e => { if (e.target === overlay) close(false); });
  }

  function toast(text, ms = 2600) {
    let el = document.querySelector('.toast');
    if (!el) { el = document.createElement('div'); el.className = 'toast'; document.body.appendChild(el); }
    el.textContent = text;
    requestAnimationFrame(() => el.classList.add('is-show'));
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('is-show'), ms);
  }

  // Stepper: current = nomor step aktif (1..n), state: 'active' | 'error'
  function setStep(stepperEl, current, state = 'active') {
    stepperEl.querySelectorAll('.step').forEach((li, i) => {
      const n = i + 1;
      li.classList.remove('is-active', 'is-done', 'is-error');
      if (n < current) li.classList.add('is-done');
      else if (n === current) li.classList.add(state === 'error' ? 'is-error' : 'is-active');
    });
  }

  function markAllDone(stepperEl) {
    stepperEl.querySelectorAll('.step').forEach(li => {
      li.classList.remove('is-active', 'is-error');
      li.classList.add('is-done');
    });
  }

  const isEmail = v => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);

  function setFieldError(fieldEl, message) {
    fieldEl.classList.toggle('has-error', !!message);
    const err = fieldEl.querySelector('.field__error');
    if (err) err.textContent = message || '';
  }

  // Tombol loading: simpan isi asli, tampilkan teks sementara
  function setLoading(btn, loading, text = 'Memproses...') {
    if (loading) {
      if (!btn.dataset.original) btn.dataset.original = btn.innerHTML;
      btn.disabled = true;
      btn.textContent = text;
    } else {
      btn.disabled = false;
      if (btn.dataset.original) btn.innerHTML = btn.dataset.original;
      delete btn.dataset.original;
    }
  }

  /* ---------- Request ke server Laravel ---------- */
  async function api(url, data = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    let res;
    try {
      res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': token,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(data)
      });
    } catch {
      return { ok: false, status: 0, data: { message: 'Tidak bisa terhubung ke server. Periksa koneksi Anda.' } };
    }

    let body = {};
    try { body = await res.json(); } catch { /* respons bukan JSON */ }

    if (res.status === 419) body.message = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    if (res.status === 429 && (!body.message || body.message === 'Too Many Attempts.')) {
      body.message = 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.';
    }
    if (!res.ok && !body.message) body.message = 'Terjadi kesalahan pada server. Coba lagi nanti.';

    return { ok: res.ok, status: res.status, data: body };
  }

  // Ambil pesan error pertama untuk satu field dari respons validasi Laravel (422)
  const fieldError = (r, name) => r.data?.errors?.[name]?.[0] || '';

  /*
   * Kotak OTP 6 digit: auto pindah fokus, backspace mundur, bisa paste, Enter = onEnter().
   * Return: { value(), clear(), setError(msg), focus() }
   */
  function otpInput(otpEl, errorEl, onEnter) {
    const boxes = [...otpEl.querySelectorAll('input')];
    const setError = msg => {
      otpEl.classList.toggle('is-error', !!msg);
      errorEl.textContent = msg || '';
    };

    boxes.forEach((box, i) => {
      box.addEventListener('input', () => {
        box.value = box.value.replace(/\D/g, '');
        setError('');
        if (box.value && boxes[i + 1]) boxes[i + 1].focus();
      });
      box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !box.value && boxes[i - 1]) boxes[i - 1].focus();
        if (e.key === 'Enter' && onEnter) onEnter();
      });
      box.addEventListener('paste', e => {
        const text = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, boxes.length);
        if (!text) return;
        e.preventDefault();
        text.split('').forEach((ch, k) => { if (boxes[k]) boxes[k].value = ch; });
        boxes[Math.min(text.length, boxes.length - 1)].focus();
      });
    });

    return {
      value: () => boxes.map(b => b.value).join(''),
      clear: () => { boxes.forEach(b => (b.value = '')); setError(''); },
      setError,
      focus: () => setTimeout(() => boxes[0].focus(), 50)
    };
  }

  /* ---------- Kamera + face-api.js ---------- */
  const Face = (() => {
    const SAMPLES = 3;
    let stream = null;
    let video = null;
    let modelsLoaded = false;

    // Pilih mesin hitung TensorFlow: WebGL (cepat, pakai GPU), kalau tidak tersedia pakai CPU.
    // Tanpa ini, browser tanpa WebGL akan error "backend 'wasm' has not yet been initialized".
    async function initBackend() {
      const tf = faceapi.tf;
      for (const name of ['webgl', 'cpu']) {
        try {
          if (await tf.setBackend(name)) {
            await tf.ready();
            return;
          }
        } catch { /* coba backend berikutnya */ }
      }
      throw new Error('Browser tidak mendukung pemrosesan wajah. Coba browser lain (Chrome/Edge terbaru).');
    }

    async function loadModels() {
      if (modelsLoaded) return;
      if (typeof faceapi === 'undefined') throw new Error('Library face-api.js tidak termuat.');
      await initBackend();
      const url = window.AppConfig.modelsUrl;
      await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(url),
        faceapi.nets.faceLandmark68Net.loadFromUri(url),
        faceapi.nets.faceRecognitionNet.loadFromUri(url)
      ]);
      modelsLoaded = true;
    }

    async function start(faceEl) {
      await loadModels();
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 }, audio: false });
      } catch (err) {
        throw new Error(err.name === 'NotAllowedError'
          ? 'Akses kamera ditolak. Izinkan kamera di pengaturan browser.'
          : 'Kamera tidak ditemukan atau sedang dipakai aplikasi lain.');
      }
      video = document.createElement('video');
      video.autoplay = true;
      video.muted = true;
      video.playsInline = true;
      video.srcObject = stream;
      faceEl.textContent = '';
      faceEl.appendChild(video);
      faceEl.classList.add('is-live');
      await video.play();
    }

    function stop(faceEl) {
      if (stream) stream.getTracks().forEach(t => t.stop());
      stream = null;
      if (video) video.remove();
      video = null;
      if (faceEl) {
        faceEl.classList.remove('is-live', 'is-scanning');
        if (!faceEl.textContent) faceEl.textContent = '(Wajah)';
      }
    }

    async function detectOnce() {
      const opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 });
      const results = await faceapi.detectAllFaces(video, opts).withFaceLandmarks().withFaceDescriptors();

      if (results.length === 0) throw new Error('Wajah tidak terdeteksi. Pastikan pencahayaan cukup.');
      if (results.length > 1) throw new Error('Terdeteksi lebih dari satu wajah. Pastikan hanya Anda di kamera.');
      if (results[0].detection.box.width < video.videoWidth * 0.2) throw new Error('Wajah terlalu jauh. Dekatkan wajah ke kamera.');

      return results[0].descriptor;
    }

    function snapshot() {
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 480;
      const size = Math.min(video.videoWidth, video.videoHeight);
      const sx = (video.videoWidth - size) / 2;
      const sy = (video.videoHeight - size) / 2;
      canvas.getContext('2d').drawImage(video, sx, sy, size, size, 0, 0, 480, 480);
      return canvas.toDataURL('image/jpeg', 0.85);
    }

    // Ambil beberapa sampel lalu dirata-rata supaya descriptor lebih stabil
    async function capture(onProgress) {
      const samples = [];
      for (let i = 0; i < SAMPLES; i++) {
        onProgress?.(i + 1, SAMPLES);
        samples.push(await detectOnce());
        await new Promise(r => setTimeout(r, 300));
      }
      const avg = new Array(128).fill(0);
      samples.forEach(d => d.forEach((v, i) => { avg[i] += v / samples.length; }));
      return { descriptor: avg.map(v => +v.toFixed(6)), image: snapshot() };
    }

    return { start, stop, capture, isRunning: () => !!stream };
  })();

  /*
   * Hubungkan tombol "Mulai Verifikasi" dengan kamera.
   *   klik 1 -> nyalakan kamera
   *   klik 2 -> pindai wajah lalu panggil submit({ descriptor, image })
   */
  function bindFaceButton({ btn, faceEl, statusEl, submit }) {
    const status = (text, type = '') => {
      statusEl.textContent = text;
      statusEl.className = 'face-status' + (type ? ' is-' + type : '');
    };

    btn.addEventListener('click', async () => {
      if (!Face.isRunning()) {
        setLoading(btn, true, 'Menyiapkan kamera...');
        status('Memuat model pengenalan wajah...');
        try {
          await Face.start(faceEl);
          setLoading(btn, false);
          btn.textContent = 'Ambil Foto';
          status('Posisikan wajah di tengah lingkaran, lalu klik "Ambil Foto".');
        } catch (err) {
          Face.stop(faceEl);
          setLoading(btn, false);
          status(err.message, 'error');
        }
        return;
      }

      setLoading(btn, true, 'Memindai wajah...');
      faceEl.classList.add('is-scanning');
      let result;
      try {
        result = await Face.capture((i, n) => status(`Memindai wajah... (${i}/${n})`));
      } catch (err) {
        faceEl.classList.remove('is-scanning');
        setLoading(btn, false);
        btn.textContent = 'Ambil Foto';
        status(err.message, 'error');
        return;
      }

      status('Wajah terdeteksi. Memverifikasi...', 'ok');
      btn.textContent = 'Memverifikasi...';
      try {
        await submit(result);
      } finally {
        Face.stop(faceEl);
        setLoading(btn, false);
        btn.textContent = 'Mulai Verifikasi';
        status('');
      }
    });
  }

  return { esc, showModal, toast, setStep, markAllDone, isEmail, setFieldError, setLoading, api, fieldError, otpInput, Face, bindFaceButton };
})();

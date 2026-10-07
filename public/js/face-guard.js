/*
 * FaceGuard — validasi wajah + liveness (anti foto)
 *
 * Memakai DUA model yang saling melengkapi:
 *   - MediaPipe Face Landmarker (478 titik + blendshape + matriks rotasi kepala), real-time ~30 fps:
 *       jumlah wajah, wajah utuh di dalam frame, sudut kepala (yaw/pitch/roll dalam derajat),
 *       kondisi mata (eyeBlinkLeft/Right), mulut (jawOpen) -> untuk revisi 2, 3, 4, 5.
 *   - face-api.js (SSD MobileNet + FaceRecognitionNet):
 *       skor deteksi wajah (wajah tertutup/masker -> skor rendah) dan descriptor 128 angka
 *       untuk mengenali identitas -> untuk revisi 1 & 3.
 *
 * Semua angka batas berasal dari config/face.php -> window.AppConfig.face (server memvalidasi ulang).
 */
window.FaceGuard = (() => {
  const cfg = () => window.AppConfig.face;
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const round = (v, d = 4) => Math.round(v * 10 ** d) / 10 ** d;
  const isDebug = () => new URLSearchParams(location.search).has('debug');

  /* ================= Metrik dari MediaPipe ================= */

  // Sudut kepala (derajat) dari matriks transformasi wajah MediaPipe (column-major 4x4).
  //   yaw   > 0 = menoleh ke KIRI pengguna, < 0 = ke KANAN
  //   pitch > 0 = menunduk, < 0 = mendongak
  //   roll       = kepala miring
  function headPose(m) {
    const r20 = m[2], r21 = m[6], r22 = m[10], r00 = m[0], r10 = m[1];
    const deg = 180 / Math.PI;
    return {
      yaw: Math.asin(Math.max(-1, Math.min(1, -r20))) * deg,
      pitch: Math.atan2(r21, r22) * deg,
      roll: Math.atan2(r10, r00) * deg
    };
  }

  /** Metrik 1 wajah dari hasil MediaPipe (koordinat ternormalisasi 0..1). */
  function mpMetrics(result, i) {
    const lm = result.faceLandmarks[i];
    const bs = Object.fromEntries(result.faceBlendshapes[i].categories.map(c => [c.categoryName, c.score]));
    const pose = headPose(result.facialTransformationMatrixes[i].data);
    let minX = 1, maxX = 0, minY = 1, maxY = 0;
    for (const p of lm) {
      if (p.x < minX) minX = p.x; if (p.x > maxX) maxX = p.x;
      if (p.y < minY) minY = p.y; if (p.y > maxY) maxY = p.y;
    }
    const m = cfg().quality.margin;
    return {
      ...pose,
      blinkL: bs.eyeBlinkLeft, blinkR: bs.eyeBlinkRight,
      blink: (bs.eyeBlinkLeft + bs.eyeBlinkRight) / 2,       // 0 = terbuka lebar, 1 = terpejam
      jaw: bs.jawOpen,                                         // 0 = mulut tertutup, 1 = terbuka lebar
      inFrame: minX >= m && maxX <= 1 - m && minY >= m && maxY <= 1 - m,   // semua 478 titik di dalam frame
      width: maxX - minX,
      cx: (minX + maxX) / 2 - 0.5,
      cy: (minY + maxY) / 2 - 0.5
    };
  }

  /*
   * Deteksi wajah tertutup (masker/kain): bandingkan warna kulit (kroma Cr/Cb) di area yang tidak
   * tertutup masker (dahi, antara alis, pangkal hidung) dengan ujung hidung, bibir, dan dagu.
   * Jika KETIGA area itu berbeda jauh dari warna kulit -> wajah tertutup.
   * Return jarak kroma minimum (wajah normal <= ~12, masker >= ~17).
   */
  const REF_POINTS = [151, 9, 168];
  const TEST_GROUPS = [[4, 1], [13, 14, 0, 17], [199]];
  let pixCanvas = null, pixCtx = null;

  function grabFrame(source) {
    const W = source.videoWidth || source.naturalWidth || source.width;
    const H = source.videoHeight || source.naturalHeight || source.height;
    if (!pixCanvas) {
      pixCanvas = document.createElement('canvas');
      pixCtx = pixCanvas.getContext('2d', { willReadFrequently: true });
    }
    if (pixCanvas.width !== W || pixCanvas.height !== H) { pixCanvas.width = W; pixCanvas.height = H; }
    pixCtx.drawImage(source, 0, 0, W, H);
    return [W, H];
  }

  // Rata-rata warna kulit (kromatisitas r, g, b yang dijumlah = 1) di dahi, antara alis, pangkal hidung, dan pipi
  const SKIN_POINTS = [151, 9, 6, 50, 280, 205, 425];
  function skinChroma(source, lm) {
    const [W, H] = grabFrame(source);
    const r = Math.max(3, Math.round(W * 0.012));
    let R = 0, G = 0, B = 0;
    for (const i of SKIN_POINTS) {
      const x = Math.round(lm[i].x * W) - r, y = Math.round(lm[i].y * H) - r;
      const d = pixCtx.getImageData(Math.max(0, x), Math.max(0, y), 2 * r + 1, 2 * r + 1).data;
      for (let k = 0; k < d.length; k += 4) { R += d[k]; G += d[k + 1]; B += d[k + 2]; }
    }
    const sum = R + G + B || 1;
    return [R / sum, G / sum, B / sum];
  }

  function coverScore(source, lm) {
    const [W, H] = grabFrame(source);
    const r = Math.max(3, Math.round(W * 0.012));
    const chroma = idxs => {
      let cr = 0, cb = 0, n = 0;
      for (const i of idxs) {
        const x = Math.round(lm[i].x * W) - r, y = Math.round(lm[i].y * H) - r;
        const d = pixCtx.getImageData(Math.max(0, x), Math.max(0, y), 2 * r + 1, 2 * r + 1).data;
        for (let k = 0; k < d.length; k += 4) {
          cr += 128 + 0.5 * d[k] - 0.418688 * d[k + 1] - 0.081312 * d[k + 2];
          cb += 128 - 0.168736 * d[k] - 0.331264 * d[k + 1] + 0.5 * d[k + 2];
          n++;
        }
      }
      return [cr / n, cb / n];
    };
    const ref = chroma(REF_POINTS);
    return Math.min(...TEST_GROUPS.map(g => {
      const c = chroma(g);
      return Math.hypot(c[0] - ref[0], c[1] - ref[1]);
    }));
  }

  /** Cek kualitas wajah (utuh, tidak tertutup, ukuran, posisi, sudut, mata). Return { ok, code, message }. */
  function checkQuality(mt, eyeLimit) {
    const q = cfg().quality;
    const fail = (code, message) => ({ ok: false, code, message });

    if (!mt.inFrame) return fail('partial', 'Wajah tidak utuh. Pastikan seluruh wajah (dahi sampai dagu) terlihat di kamera.');
    if (mt.width < q.min_width) return fail('far', 'Wajah terlalu jauh. Dekatkan wajah ke kamera.');
    if (mt.width > q.max_width) return fail('near', 'Wajah terlalu dekat. Mundurkan sedikit wajah Anda.');
    if (mt.cover != null && mt.cover >= q.cover_max) return fail('covered', 'Wajah tertutup (masker/kain). Lepaskan penutup wajah agar seluruh wajah terlihat.');
    if (Math.abs(mt.cx) > q.max_center || Math.abs(mt.cy) > q.max_center) return fail('center', 'Posisikan wajah di tengah lingkaran.');
    if (Math.abs(mt.roll) > q.max_roll) return fail('tilt', 'Kepala miring. Tegakkan posisi kepala.');
    if (Math.abs(mt.yaw) > q.max_yaw) return fail('side', 'Wajah menghadap ke samping. Hadapkan wajah lurus ke kamera.');
    if (mt.pitch < q.pitch_min) return fail('up', 'Wajah terlalu mendongak. Luruskan pandangan ke kamera.');
    if (mt.pitch > q.pitch_max) return fail('down', 'Wajah terlalu menunduk. Luruskan pandangan ke kamera.');
    if (mt.blink > (eyeLimit ?? q.eye_open_max)) return fail('eyes', 'Mata terdeteksi tertutup. Buka mata Anda dan tatap kamera.');
    return { ok: true, code: 'ok', message: 'Wajah terdeteksi dengan baik.' };
  }

  /* ================= Model & kamera ================= */
  let stream = null, video = null, overlay = null, cameraLabel = '';
  let landmarker = null, faceApiReady = false, lastTs = -1;

  async function initTf() {
    const tf = faceapi.tf;
    for (const name of ['webgl', 'cpu']) {
      try {
        if (await tf.setBackend(name)) { await tf.ready(); return; }
      } catch { /* coba berikutnya */ }
    }
    throw new Error('Browser tidak mendukung pemrosesan wajah. Coba Chrome/Edge terbaru.');
  }

  async function loadModels() {
    if (typeof faceapi === 'undefined' || typeof Vision === 'undefined') {
      throw new Error('Library pengenalan wajah tidak termuat. Muat ulang halaman.');
    }
    const url = window.AppConfig.modelsUrl;

    if (!faceApiReady) {
      await initTf();
      await Promise.all([
        faceapi.nets.ssdMobilenetv1.loadFromUri(url),
        faceapi.nets.faceLandmark68Net.loadFromUri(url),
        faceapi.nets.faceRecognitionNet.loadFromUri(url)
      ]);
      faceApiReady = true;
    }

    if (!landmarker) {
      const files = await Vision.FilesetResolver.forVisionTasks(window.AppConfig.mediapipeWasm);
      const make = delegate => Vision.FaceLandmarker.createFromOptions(files, {
        baseOptions: { modelAssetPath: url + '/face_landmarker.task', delegate },
        runningMode: 'VIDEO',
        numFaces: 2,                       // 2 supaya bisa menolak jika ada lebih dari satu wajah
        outputFaceBlendshapes: true,
        outputFacialTransformationMatrixes: true
      });
      try { landmarker = await make('GPU'); } catch { landmarker = await make('CPU'); }
    }
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
    // Kamera virtual (OBS, ManyCam, dll.) bisa memutar video sebagai kamera -> ditolak
    cameraLabel = stream.getVideoTracks()[0]?.label || '';
    const blocked = cfg().blocked_cameras;
    if (blocked && new RegExp(blocked, 'i').test(cameraLabel)) {
      const name = cameraLabel;
      stop(faceEl);
      throw new Error(`Kamera virtual terdeteksi ("${name}"). Gunakan kamera asli perangkat Anda.`);
    }
    video = document.createElement('video');
    Object.assign(video, { autoplay: true, muted: true, playsInline: true, srcObject: stream });
    faceEl.textContent = '';
    faceEl.appendChild(video);
    faceEl.classList.add('is-live');
    await video.play();
    if (!video.videoWidth) await new Promise(r => video.addEventListener('loadedmetadata', r, { once: true }));

    if (isDebug()) {
      overlay = document.createElement('canvas');
      overlay.className = 'face__overlay';
      overlay.width = video.videoWidth;
      overlay.height = video.videoHeight;
      faceEl.appendChild(overlay);
    }
  }

  function stop(faceEl) {
    if (stream) stream.getTracks().forEach(t => t.stop());
    stream = null;
    video?.remove(); video = null;
    overlay?.remove(); overlay = null;
    if (faceEl) {
      faceEl.classList.remove('is-live', 'is-scanning');
      if (!faceEl.textContent) faceEl.textContent = '(Wajah)';
    }
  }

  // Satu frame MediaPipe (real-time)
  function detectMP() {
    let ts = performance.now();
    if (ts <= lastTs) ts = lastTs + 1;
    lastTs = ts;
    return landmarker.detectForVideo(video, ts);
  }

  function snapshot() {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 480;
    const size = Math.min(video.videoWidth, video.videoHeight);
    canvas.getContext('2d').drawImage(video, (video.videoWidth - size) / 2, (video.videoHeight - size) / 2, size, size, 0, 0, 480, 480);
    return canvas.toDataURL('image/jpeg', 0.85);
  }

  function drawDebug(result, mt, info) {
    const panel = document.getElementById('face-debug');
    if (panel) {
      panel.textContent = (mt
        ? `wajah=${result.faceLandmarks.length}  yaw=${mt.yaw.toFixed(1)}°  pitch=${mt.pitch.toFixed(1)}°  roll=${mt.roll.toFixed(1)}°\n` +
          `mata(blink) L=${mt.blinkL.toFixed(2)} R=${mt.blinkR.toFixed(2)}  mulut=${mt.jaw.toFixed(2)}  lebar=${mt.width.toFixed(2)}  utuh=${mt.inFrame}` +
          (mt.cover != null ? `  tertutup=${mt.cover.toFixed(1)}` : '') + '\n'
        : `wajah=${result?.faceLandmarks.length ?? 0}\n`) + (info || '');
    }
    if (!overlay || !result) return;
    const ctx = overlay.getContext('2d');
    ctx.clearRect(0, 0, overlay.width, overlay.height);
    ctx.fillStyle = result.faceLandmarks.length === 1 ? 'rgba(18,183,106,.9)' : 'rgba(240,68,56,.9)';
    result.faceLandmarks.forEach(lm => lm.forEach((p, i) => {
      if (i % 3 === 0) ctx.fillRect(p.x * overlay.width - 1, p.y * overlay.height - 1, 2, 2);
    }));
  }

  /* ================= Tantangan gerakan (logika murni, bisa diuji tanpa kamera) ================= */
  const STEP_TEXT = {
    blink: 'Pejamkan mata sebentar, lalu buka kembali',
    turn_left: 'Tolehkan kepala ke KIRI Anda, lalu kembali lurus',
    turn_right: 'Tolehkan kepala ke KANAN Anda, lalu kembali lurus',
    open_mouth: 'Buka mulut Anda lebar-lebar, lalu tutup kembali'
  };
  const STEP_LABEL = { blink: 'Kedipkan mata', turn_left: 'Toleh ke kiri', turn_right: 'Toleh ke kanan', open_mouth: 'Buka mulut' };

  /*
   * Pelacak 1 tantangan. update(metrik, ms sejak instruksi muncul) mengembalikan state:
   *   waiting -> active (gerakan dimulai) -> done (kembali normal), atau fail.
   * Anti video rekaman:
   *   - gerakan menoleh/buka mulut sebelum react_min_ms = terlalu cepat untuk reaksi manusia -> gagal
   *   - gerakan LAIN dari yang diminta (toleh arah salah, buka mulut saat diminta kedip, dll.) -> gagal
   *   - kedip alami sebelum react_min_ms diabaikan (bukan dianggap gagal)
   */
  function createStepTracker(type, baseline, C) {
    const Q = C.quality, L = C.liveness;
    let phase = 0, peak = 0, onset = 0;
    const fail = (code, message) => ({ state: 'fail', code, message });

    const wrongAction = mt => {
      if (type === 'turn_left' && -mt.yaw >= L.turn_deg) return 'menoleh ke kanan';
      if (type === 'turn_right' && mt.yaw >= L.turn_deg) return 'menoleh ke kiri';
      if ((type === 'blink' || type === 'open_mouth') && Math.abs(mt.yaw) >= L.turn_deg) return 'menoleh';
      if (type !== 'open_mouth' && mt.jaw >= L.mouth_open) return 'membuka mulut';
      return null;
    };

    return {
      update(mt, t) {
        if (t > L.step_timeout * 1000) {
          return fail('liveness', `Tantangan "${STEP_LABEL[type]}" tidak terdeteksi. ` +
            'Sistem tidak dapat memastikan Anda hadir langsung (bukan foto/video). Silakan ulangi.');
        }
        const wrong = wrongAction(mt);
        if (wrong) {
          return fail('wrong_action', `Gerakan tidak sesuai instruksi: terdeteksi ${wrong}, padahal diminta "${STEP_LABEL[type]}". ` +
            'Ikuti hanya instruksi yang muncul di layar.');
        }

        let value, acting, back;
        if (type === 'blink') {
          value = mt.blink;
          acting = mt.blink >= L.blink_closed && mt.blink - baseline >= L.blink_delta;
          back = mt.blink <= baseline + L.blink_reopen;
          if (phase === 0 && Math.abs(mt.yaw) > Q.max_yaw * 1.5) return { state: 'waiting', hint: 'front' };
          if (phase === 0 && acting && t < L.react_min_ms) return { state: 'waiting' };   // kedip alami, abaikan
        } else if (type === 'turn_left' || type === 'turn_right') {
          value = type === 'turn_left' ? mt.yaw : -mt.yaw;
          acting = value >= L.turn_deg;
          back = value < Q.max_yaw;
        } else {
          value = mt.jaw;
          acting = value >= L.mouth_open;
          back = value <= L.mouth_closed;
        }

        if (phase === 0) {
          if (!acting) return { state: 'waiting' };
          if (t < L.react_min_ms) {
            return fail('too_early', 'Gerakan terjadi sebelum instruksi muncul. Tunggu instruksi, lalu lakukan gerakannya.');
          }
          phase = 1; peak = value; onset = t;
          return { state: 'active' };
        }
        peak = Math.max(peak, value);
        if (back) {
          return { state: 'done', result: { type, value: round(peak), onset_ms: Math.round(onset), ms: Math.round(t) } };
        }
        return { state: 'active' };
      }
    };
  }

  /* ================= Cek pantulan warna layar (logika murni) ================= */
  const FLASH_RGB = { red: [1, 0, 0], green: [0, 1, 0], blue: [0, 0, 1] };
  const FLASH_CSS = { red: '#ff0000', green: '#00ff00', blue: '#0000ff' };

  /*
   * colors : urutan warna yang ditampilkan, mis. ['red','blue','green',...]
   * frames : [{ t: ms sejak kedipan pertama, c: [r, g, b] kromatisitas kulit }]
   * Return { corr, amp, frames, ok }:
   *   corr = kemiripan pola perubahan warna kulit dengan pola warna layar (-1..1)
   *   amp  = besar perubahan warna kulit (wajah asli > layar HP)
   */
  function analyzeFlash(colors, frames, epochMs, skipMs) {
    // Rata-rata warna kulit per warna layar; warna yang tidak kebagian frame (kamera tersendat) diabaikan
    const epochs = colors.map((color, i) => {
      const inEpoch = frames.filter(f => f.t >= i * epochMs + skipMs && f.t < (i + 1) * epochMs);
      if (!inEpoch.length) return null;
      return { color, mean: [0, 1, 2].map(k => inEpoch.reduce((s, f) => s + f.c.at(k), 0) / inEpoch.length) };
    }).filter(Boolean);
    if (epochs.length < 4 || new Set(epochs.map(e => e.color)).size < 3) {
      return { corr: 0, amp: 0, frames: frames.length, ok: false };
    }
    const means = epochs.map(e => e.mean);

    const center = rows => {
      const avg = [0, 1, 2].map(k => rows.reduce((s, r) => s + r.at(k), 0) / rows.length);
      return rows.map(r => r.map((v, k) => v - avg.at(k)));
    };
    const m = center(means).flat();
    const e = center(epochs.map(ep => FLASH_RGB[ep.color])).flat();
    const dot = m.reduce((s, v, i) => s + v * e.at(i), 0);
    const nm = Math.sqrt(m.reduce((s, v) => s + v * v, 0));
    const ne = Math.sqrt(e.reduce((s, v) => s + v * v, 0));
    const corr = nm && ne ? dot / (nm * ne) : 0;
    const amp = Math.sqrt(m.reduce((s, v) => s + v * v, 0) / m.length);
    return { corr: round(corr), amp: round(amp, 5), frames: frames.length, ok: true };
  }

  /* ================= Alur verifikasi (liveness + sampel) ================= */
  class VerifyError extends Error {
    constructor(code, message) { super(message); this.code = code; }
  }

  function renderSteps(listEl, items, activeIndex) {
    if (!listEl) return;
    listEl.innerHTML = items.map((label, i) => {
      const cls = activeIndex < 0 || i < activeIndex ? 'is-done' : i === activeIndex ? 'is-active' : '';
      return `<li class="${cls}"><span class="liveness__dot"></span>${label}</li>`;
    }).join('');
  }

  /**
   * Jalankan verifikasi lengkap.
   *   challenge : { nonce, steps: [...3 tantangan], flash: ['red','blue',...] | null } dari server
   *   samples   : { before: n, after: n }
   * Return payload untuk dikirim ke server.
   */
  async function verify({ challenge, samples, onStatus, stepsEl }) {
    const C = cfg(), Q = C.quality, L = C.liveness, FL = C.flash;
    const startedAt = performance.now();
    const deadline = startedAt + L.total_timeout * 1000;
    const collected = [];
    const stepResults = [];
    let lostSince = null, lastCenter = null, baseline = null, flashResult = null;

    const status = (msg, type = '') => onStatus?.(msg, type);
    const steps = challenge.steps;
    const flashColors = challenge.flash || null;
    const items = ['Posisikan wajah', ...(flashColors ? ['Cek pantulan layar'] : []), ...steps.map(s => STEP_LABEL[s]), 'Ambil sampel wajah'];
    let itemIndex = 0;
    const nextItem = () => renderSteps(stepsEl, items, ++itemIndex);
    renderSteps(stepsEl, items, 0);

    // Satu frame: tepat 1 wajah, tidak "melompat" (anti ganti orang/foto di tengah jalan)
    async function frame(withCover = false) {
      if (performance.now() > deadline) throw new VerifyError('timeout', 'Waktu verifikasi habis. Silakan ulangi.');
      await new Promise(r => requestAnimationFrame(r));
      const res = detectMP();
      const n = res.faceLandmarks.length;
      if (n !== 1) {
        drawDebug(res, null, n ? 'lebih dari 1 wajah' : 'tidak ada wajah');
        lostSince ??= performance.now();
        if (performance.now() - lostSince > L.max_lost_ms) {
          throw new VerifyError(n ? 'multi' : 'lost', n
            ? 'Terdeteksi lebih dari satu wajah. Pastikan hanya Anda di depan kamera.'
            : 'Wajah hilang dari kamera. Tetap berada di depan kamera selama verifikasi.');
        }
        status(n ? 'Terdeteksi lebih dari satu wajah.' : 'Wajah tidak terdeteksi. Hadapkan wajah ke kamera.', 'error');
        return null;
      }
      lostSince = null;
      const mt = mpMetrics(res, 0);
      if (withCover) mt.cover = coverScore(video, res.faceLandmarks[0]);
      const center = { x: mt.cx, y: mt.cy };
      if (lastCenter && Math.hypot(center.x - lastCenter.x, center.y - lastCenter.y) > L.max_jump) {
        throw new VerifyError('jump', 'Posisi wajah berubah terlalu cepat. Ulangi dan tetap di depan kamera.');
      }
      lastCenter = center;
      return { res, mt };
    }

    // Tunggu wajah stabil: utuh, lurus, mata terbuka selama beberapa frame. Return nilai mata normal (median).
    async function align(eyeLimit) {
      let stable = 0;
      const blinks = [];
      while (stable < Q.stable_frames) {
        const f = await frame(true);
        if (!f) { stable = 0; continue; }
        const q = checkQuality(f.mt, eyeLimit);
        drawDebug(f.res, f.mt, q.ok ? `stabil ${stable + 1}/${Q.stable_frames}` : `ditolak: ${q.code}`);
        if (!q.ok) { stable = 0; blinks.length = 0; status(q.message, 'error'); continue; }
        stable++; blinks.push(f.mt.blink);
        status('Tahan posisi...', '');
      }
      blinks.sort((a, b) => a - b);
      return blinks[Math.floor(blinks.length / 2)];
    }

    // Ambil sampel descriptor: face-api SSD (akurat) + cek MediaPipe pada momen yang sama
    async function capture(n, eyeLimit) {
      let got = 0, tries = 0;
      while (got < n) {
        if (++tries > n * 8) {
          throw new VerifyError('quality', 'Wajah kurang jelas atau sebagian tertutup (masker/tangan/kacamata gelap). Perbaiki lalu ulangi.');
        }
        const f = await frame(true);
        if (!f) continue;
        const q = checkQuality(f.mt, eyeLimit);
        if (!q.ok) { status(q.message, 'error'); drawDebug(f.res, f.mt, `sampel ditolak: ${q.code}`); continue; }

        status(`Mengambil sampel wajah... (${collected.length + 1}/${samples.before + samples.after})`, '');
        const dets = await faceapi.detectAllFaces(video, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.3 }))
          .withFaceLandmarks().withFaceDescriptors();
        if (dets.length !== 1) { await sleep(100); continue; }

        const det = dets[0];
        const b = det.detection.box, W = video.videoWidth, H = video.videoHeight, m = Q.margin;
        const boxInFrame = b.x >= W * m && b.y >= H * m && b.x + b.width <= W * (1 - m) && b.y + b.height <= H * (1 - m);
        drawDebug(f.res, f.mt, `skor SSD=${det.detection.score.toFixed(2)} kotak utuh=${boxInFrame}`);
        if (det.detection.score < Q.min_score || !boxInFrame) {
          status('Wajah kurang jelas atau sebagian tertutup. Lepas masker/kacamata gelap dan jangan tutupi wajah.', 'error');
          await sleep(150);
          continue;
        }

        collected.push({
          descriptor: Array.from(det.descriptor, v => round(v, 6)),
          metrics: {
            score: round(det.detection.score), yaw: round(f.mt.yaw, 2), pitch: round(f.mt.pitch, 2), roll: round(f.mt.roll, 2),
            blink: round(f.mt.blink), jaw: round(f.mt.jaw), width: round(f.mt.width), cover: round(f.mt.cover, 2),
            in_frame: f.mt.inFrame && boxInFrame
          }
        });
        got++;
        await sleep(Q.sample_interval_ms);
      }
    }

    // Layar berkedip warna acak; warna kulit wajah diukur tiap frame
    async function flashCheck(colors) {
      const veil = document.createElement('div');
      veil.className = 'flash-overlay';
      veil.innerHTML = '<p>Tetap tatap kamera...<br>Layar sedang memeriksa pantulan cahaya pada wajah Anda.</p>';
      document.body.appendChild(veil);
      const frames = [];
      let shown = -1, lm = null;
      // Posisi wajah cukup dideteksi sesekali (wajah diam); warna kulit diukur tiap frame -> ringan, banyak sampel
      while (!lm) { const f = await frame(); lm = f?.res.faceLandmarks[0] ?? null; }
      const t0 = performance.now();
      try {
        while (true) {
          const t = performance.now() - t0;
          const i = Math.floor(t / FL.epoch_ms);
          if (i >= colors.length) break;
          if (i !== shown) { veil.style.background = FLASH_CSS[colors.at(i)]; shown = i; }
          await new Promise(r => requestAnimationFrame(r));
          frames.push({ t: performance.now() - t0, c: skinChroma(video, lm) });
        }
      } finally {
        veil.remove();
      }
      // Wajah harus masih ada di tempat yang sama setelah kedipan (frame() menolak bila hilang/melompat)
      let after = null;
      while (!after) after = await frame();
      const r = analyzeFlash(colors, frames, FL.epoch_ms, FL.skip_ms);
      drawDebug(null, null, `pantulan layar: corr=${r.corr} amp=${r.amp} frame=${r.frames}`);
      if (isDebug()) console.info('[face] pantulan layar', r);
      if (!r.ok) {
        throw new VerifyError('flash', 'Kamera terlalu lambat untuk memeriksa pantulan layar. Tutup aplikasi lain lalu ulangi.');
      }
      if (r.corr < FL.min_corr || r.amp < FL.min_amp) {
        throw new VerifyError('replay', 'Pantulan cahaya layar pada wajah tidak terdeteksi. Wajah kemungkinan ditampilkan dari layar/video. ' +
          'Jika ini wajah asli, naikkan kecerahan layar, dekatkan wajah, dan hindari ruangan yang terlalu terang.');
      }
      return { colors, corr: r.corr, amp: r.amp, frames: r.frames };
    }

    // Jeda acak lalu satu tantangan
    async function challengeStep(type) {
      const gap = L.gap_min_ms + Math.random() * (L.gap_max_ms - L.gap_min_ms);
      const g0 = performance.now();
      status('Bersiap... tetap lurus menghadap kamera', '');
      while (performance.now() - g0 < gap) await frame();

      const tracker = createStepTracker(type, baseline, C);
      const t0 = performance.now();
      status(STEP_TEXT[type], 'action');
      while (true) {
        const f = await frame();
        if (!f) continue;
        drawDebug(f.res, f.mt, `tantangan ${type}`);
        if (!f.mt.inFrame) { status('Wajah keluar dari frame. ' + STEP_TEXT[type], 'error'); continue; }
        const r = tracker.update(f.mt, performance.now() - t0);
        if (r.state === 'fail') throw new VerifyError(r.code, r.message);
        if (r.state === 'done') { stepResults.push(r.result); return; }
        status(r.hint === 'front' ? 'Hadapkan wajah lurus, lalu pejamkan mata sebentar.' : STEP_TEXT[type], 'action');
      }
    }

    // ---- Urutan: posisi -> sampel awal -> kedipan warna -> tantangan acak -> posisi -> sampel akhir ----
    status('Posisikan wajah lurus di tengah lingkaran.', '');
    baseline = await align(Q.eye_open_max);
    const eyeLimit = Math.min(Q.eye_open_max, baseline + L.blink_reopen);
    await capture(samples.before, eyeLimit);
    nextItem();

    if (flashColors) {
      status('Layar akan berkedip warna. Tetap tatap kamera.', 'action');
      await sleep(600);
      flashResult = await flashCheck(flashColors);
      nextItem();
    }

    for (const type of steps) {
      await challengeStep(type);
      nextItem();
    }

    status('Kembali lurus menghadap kamera.', '');
    await align(eyeLimit);
    await capture(samples.after, eyeLimit);
    const image = snapshot();
    renderSteps(stepsEl, items, -1);

    return {
      nonce: challenge.nonce,
      samples: collected,
      eye_baseline: round(baseline),
      steps: stepResults,
      flash: flashResult,
      camera: cameraLabel,
      duration_ms: Math.round(performance.now() - startedAt),
      image
    };
  }

  return {
    headPose, mpMetrics, coverScore, checkQuality,      // dipakai juga untuk pengujian/kalibrasi
    createStepTracker, analyzeFlash,
    loadModels, start, stop, verify, VerifyError,
    isRunning: () => !!stream
  };
})();

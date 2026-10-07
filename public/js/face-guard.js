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

  function coverScore(source, lm) {
    const W = source.videoWidth || source.naturalWidth || source.width;
    const H = source.videoHeight || source.naturalHeight || source.height;
    if (!pixCanvas) {
      pixCanvas = document.createElement('canvas');
      pixCtx = pixCanvas.getContext('2d', { willReadFrequently: true });
    }
    if (pixCanvas.width !== W || pixCanvas.height !== H) { pixCanvas.width = W; pixCanvas.height = H; }
    pixCtx.drawImage(source, 0, 0, W, H);
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
  let stream = null, video = null, overlay = null;
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

  /* ================= Alur verifikasi (liveness + sampel) ================= */
  const STEP_TEXT = {
    blink: 'Pejamkan mata sebentar, lalu buka kembali',
    turn_left: 'Tolehkan kepala ke KIRI Anda, lalu kembali lurus',
    turn_right: 'Tolehkan kepala ke KANAN Anda, lalu kembali lurus',
    open_mouth: 'Buka mulut Anda lebar-lebar, lalu tutup kembali'
  };
  const STEP_LABEL = { blink: 'Kedipkan mata', turn_left: 'Toleh ke kiri', turn_right: 'Toleh ke kanan', open_mouth: 'Buka mulut' };

  class VerifyError extends Error {
    constructor(code, message) { super(message); this.code = code; }
  }

  function renderSteps(listEl, steps, activeIndex, doneUntil) {
    if (!listEl) return;
    const items = ['Posisikan wajah', ...steps.map(s => STEP_LABEL[s]), 'Ambil sampel wajah'];
    listEl.innerHTML = items.map((label, i) => {
      const cls = i < doneUntil ? 'is-done' : i === activeIndex ? 'is-active' : '';
      return `<li class="${cls}"><span class="liveness__dot"></span>${label}</li>`;
    }).join('');
  }

  /**
   * Jalankan verifikasi lengkap.
   *   challenge : { nonce, steps: ['blink', 'turn_left', ...] } dari server
   *   samples   : { before: n, after: n }
   * Return payload untuk dikirim ke server.
   */
  async function verify({ challenge, samples, onStatus, stepsEl }) {
    const C = cfg(), Q = C.quality, L = C.liveness;
    const startedAt = performance.now();
    const deadline = startedAt + L.total_timeout * 1000;
    const collected = [];
    const stepResults = [];
    let lostSince = null, lastCenter = null, baseline = null;

    const status = (msg, type = '') => onStatus?.(msg, type);
    const steps = challenge.steps;
    renderSteps(stepsEl, steps, 0, 0);

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

    // Satu tantangan liveness
    async function challengeStep(type) {
      const t0 = performance.now();
      let phase = 0;          // 0 = menunggu aksi, 1 = aksi terdeteksi -> menunggu kembali normal
      let peak = null;
      status(STEP_TEXT[type], 'action');
      while (true) {
        if (performance.now() - t0 > L.step_timeout * 1000) {
          throw new VerifyError('liveness', `Tantangan "${STEP_LABEL[type]}" tidak terdeteksi. ` +
            'Sistem tidak dapat memastikan Anda hadir langsung (bukan foto/video). Silakan ulangi.');
        }
        const f = await frame();
        if (!f) continue;
        const mt = f.mt;
        drawDebug(f.res, mt, `tantangan ${type} fase ${phase}`);
        if (!mt.inFrame) { status('Wajah keluar dari frame. ' + STEP_TEXT[type], 'error'); continue; }

        if (type === 'blink') {
          // Kedip hanya dihitung saat wajah menghadap depan
          if (Math.abs(mt.yaw) > Q.max_yaw * 1.5) { status('Hadapkan wajah lurus, lalu pejamkan mata sebentar.', 'action'); continue; }
          const closed = mt.blink >= L.blink_closed && mt.blink - baseline >= L.blink_delta;
          if (phase === 0 && closed) { phase = 1; peak = mt.blink; }
          else if (phase === 1) {
            peak = Math.max(peak, mt.blink);
            if (mt.blink <= baseline + L.blink_reopen) break;      // mata terbuka lagi
          }
        } else if (type === 'turn_left' || type === 'turn_right') {
          const yaw = type === 'turn_left' ? mt.yaw : -mt.yaw;
          if (phase === 0 && yaw >= L.turn_deg) { phase = 1; peak = yaw; }
          else if (phase === 1) {
            peak = Math.max(peak, yaw);
            if (yaw < Q.max_yaw) break;                             // sudah kembali lurus
          }
        } else if (type === 'open_mouth') {
          if (phase === 0 && mt.jaw >= L.mouth_open) { phase = 1; peak = mt.jaw; }
          else if (phase === 1) {
            peak = Math.max(peak, mt.jaw);
            if (mt.jaw <= L.mouth_closed) break;
          }
        }
      }
      stepResults.push({ type, value: round(peak), ms: Math.round(performance.now() - t0) });
    }

    // ---- Urutan: posisi -> sampel awal -> tantangan acak -> posisi -> sampel akhir ----
    status('Posisikan wajah lurus di tengah lingkaran.', '');
    baseline = await align(Q.eye_open_max);
    const eyeLimit = Math.min(Q.eye_open_max, baseline + L.blink_reopen);
    await capture(samples.before, eyeLimit);
    renderSteps(stepsEl, steps, 1, 1);

    for (let i = 0; i < steps.length; i++) {
      await challengeStep(steps[i]);
      renderSteps(stepsEl, steps, i + 2, i + 2);
    }

    status('Kembali lurus menghadap kamera.', '');
    await align(eyeLimit);
    await capture(samples.after, eyeLimit);
    const image = snapshot();
    renderSteps(stepsEl, steps, -1, steps.length + 2);

    return {
      nonce: challenge.nonce,
      samples: collected,
      eye_baseline: round(baseline),
      steps: stepResults,
      duration_ms: Math.round(performance.now() - startedAt),
      image
    };
  }

  return {
    headPose, mpMetrics, coverScore, checkQuality,     // dipakai juga untuk pengujian/kalibrasi
    loadModels, start, stop, verify, VerifyError,
    isRunning: () => !!stream
  };
})();

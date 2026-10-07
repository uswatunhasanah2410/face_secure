/*
 * Unit test logika anti-video di public/js/face-guard.js (tanpa kamera/browser).
 * Jalankan: node --test tests/js
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Muat face-guard.js dengan "window" palsu
const window = {
  AppConfig: {
    face: {
      quality: { max_yaw: 12, margin: 0.02 },
      liveness: {
        turn_deg: 20, blink_closed: 0.45, blink_delta: 0.30, blink_reopen: 0.15,
        mouth_open: 0.40, mouth_closed: 0.15, step_timeout: 7, react_min_ms: 300, react_max_ms: 6000
      }
    }
  }
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/js/face-guard.js'), 'utf8'), { window, location: { search: '' } });
const { createStepTracker, analyzeFlash } = window.FaceGuard;
const C = window.AppConfig.face;

const neutral = { yaw: 3, blink: 0.05, jaw: 0.02 };
const at = (o) => ({ ...neutral, ...o });

// Jalankan rangkaian [ms, metrik] ke tracker, kembalikan hasil terakhir
function run(type, seq) {
  const tr = createStepTracker(type, 0.05, C);
  let r;
  for (const [t, mt] of seq) {
    r = tr.update(mt, t);
    if (r.state === 'fail' || r.state === 'done') return r;
  }
  return r;
}

test('kedip normal setelah instruksi -> lolos', () => {
  const r = run('blink', [[100, neutral], [600, at({ blink: 0.8 })], [700, at({ blink: 0.85 })], [850, neutral]]);
  assert.equal(r.state, 'done');
  assert.equal(r.result.onset_ms, 600);
});

test('kedip alami tepat saat instruksi muncul diabaikan, kedip berikutnya dihitung', () => {
  const r = run('blink', [[100, at({ blink: 0.8 })], [200, neutral], [900, at({ blink: 0.8 })], [1000, neutral]]);
  assert.equal(r.state, 'done');
  assert.equal(r.result.onset_ms, 900);
});

test('menoleh sebelum 300 ms (video sudah bergerak duluan) -> gagal too_early', () => {
  const r = run('turn_left', [[120, at({ yaw: 25 })]]);
  assert.equal(r.state, 'fail');
  assert.equal(r.code, 'too_early');
});

test('menoleh ke arah yang salah -> gagal wrong_action', () => {
  const r = run('turn_left', [[500, at({ yaw: -10 })], [700, at({ yaw: -24 })]]);
  assert.equal(r.code, 'wrong_action');
});

test('buka mulut saat diminta kedip (video berisi semua gerakan) -> gagal', () => {
  const r = run('blink', [[500, at({ jaw: 0.6 })]]);
  assert.equal(r.code, 'wrong_action');
});

test('menoleh saat diminta buka mulut -> gagal', () => {
  const r = run('open_mouth', [[500, at({ yaw: 26 })]]);
  assert.equal(r.code, 'wrong_action');
});

test('kedip alami saat diminta menoleh tidak dianggap salah', () => {
  const r = run('turn_right', [[400, at({ blink: 0.9 })], [900, at({ yaw: -25 })], [1300, at({ yaw: -5 })]]);
  assert.equal(r.state, 'done');
});

test('tidak bergerak sama sekali (foto) -> gagal setelah batas waktu', () => {
  const seq = Array.from({ length: 80 }, (_, i) => [i * 100, neutral]);
  assert.equal(run('blink', seq).code, 'liveness');
});

// ---------- Pantulan warna ----------
const COLORS = ['red', 'blue', 'green', 'red', 'green', 'blue'];
const EPOCH = 450, SKIP = 150;
const RGB = { red: [1, 0, 0], green: [0, 1, 0], blue: [0, 0, 1] };
const skin = [0.45, 0.33, 0.22];
let seed = 7;
const noise = (s) => { seed = (seed * 16807) % 2147483647; return (seed / 2147483647 - 0.5) * 2 * s; };

// Kamera ~25 fps, pantulan muncul dengan jeda (latency) tertentu
function frames(strength, latencyMs, noiseLevel) {
  const out = [];
  for (let t = 0; t < COLORS.length * EPOCH; t += 40) {
    const i = Math.floor(Math.max(0, t - latencyMs) / EPOCH);
    const tint = RGB[COLORS[Math.min(i, COLORS.length - 1)]];
    const c = skin.map((v, k) => v + strength * (tint[k] - 1 / 3) + noise(noiseLevel));
    const sum = c[0] + c[1] + c[2];
    out.push({ t, c: c.map(v => v / sum) });
  }
  return out;
}

test('wajah asli memantulkan warna layar -> corr tinggi', () => {
  const r = analyzeFlash(COLORS, frames(0.012, 100, 0.002), EPOCH, SKIP);
  assert.ok(r.corr > 0.8, `corr=${r.corr}`);
  assert.ok(r.amp > 0.0015, `amp=${r.amp}`);
});

test('wajah asli dengan pantulan lemah & kamera lambat (latency 140 ms) tetap lolos', () => {
  const r = analyzeFlash(COLORS, frames(0.005, 140, 0.002), EPOCH, SKIP);
  assert.ok(r.corr >= 0.5, `corr=${r.corr}`);
});

test('video di layar HP: warna kulit tidak ikut berubah -> corr rendah', () => {
  const r = analyzeFlash(COLORS, frames(0, 0, 0.002), EPOCH, SKIP);
  assert.ok(r.corr < 0.5 || r.amp < 0.0015, `corr=${r.corr} amp=${r.amp}`);
});

test('satu warna terlewat karena kamera tersendat -> tetap dianalisis dari warna lain', () => {
  const gappy = frames(0.012, 100, 0.002).filter(f => !(f.t >= 1350 && f.t < 1800));
  const r = analyzeFlash(COLORS, gappy, EPOCH, SKIP);
  assert.ok(r.ok && r.corr > 0.8, `ok=${r.ok} corr=${r.corr}`);
});

test('kamera terlalu lambat (kurang dari 4 warna terukur) -> ok=false', () => {
  const sparse = frames(0.012, 100, 0.002).filter(f => f.t < 900);
  assert.equal(analyzeFlash(COLORS, sparse, EPOCH, SKIP).ok, false);
});

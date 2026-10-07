{{--
    Kamera bulat + face-api.js.
    Alur tombol #btn-start-face:
      klik 1 -> muat model + nyalakan kamera
      klik 2 -> ambil 3 sampel wajah, rata-ratakan descriptor (128 angka),
                isi input face_descriptor + face_image, lalu submit form
    Model ada di public/models, library di public/vendor/face-api (bisa jalan offline).
--}}
@props(['formId'])

<div class="face-circle" id="face-circle">
    <span id="face-placeholder">(Wajah)</span>
    <video id="face-video" autoplay playsinline muted style="display:none"></video>
</div>
<canvas id="face-canvas" width="480" height="480" style="display:none"></canvas>
<p class="face-status" id="face-status"></p>

@once
    @push('styles')
    <style>
        .face-status { text-align: center; font-size: 15px; min-height: 22px; margin-top: 12px; color: var(--blue); }
        .face-status.error { color: var(--red); }
        .face-status.ok { color: var(--green); }
    </style>
    @endpush
@endonce

@push('scripts')
<script src="{{ asset('vendor/face-api/face-api.js') }}"></script>
<script>
(function () {
    const MODEL_URL = @json(asset('models'));
    const SAMPLES = 3;

    const form   = document.getElementById(@json($formId));
    const btn    = document.getElementById('btn-start-face');
    const video  = document.getElementById('face-video');
    const canvas = document.getElementById('face-canvas');
    const circle = document.getElementById('face-circle');
    const holder = document.getElementById('face-placeholder');
    const status = document.getElementById('face-status');
    const inDesc = form.querySelector('input[name="face_descriptor"]');
    const inImg  = form.querySelector('input[name="face_image"]');

    let stream = null;
    let modelsLoaded = false;
    const detectorOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 });

    const setStatus = (text, type = '') => { status.textContent = text; status.className = 'face-status ' + type; };
    const sleep = ms => new Promise(r => setTimeout(r, ms));

    async function loadModels() {
        if (modelsLoaded) return;
        setStatus('Memuat model pengenalan wajah...');
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
        ]);
        modelsLoaded = true;
    }

    async function startCamera() {
        btn.disabled = true;
        try {
            await loadModels();
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 }, audio: false });
            video.srcObject = stream;
            await video.play();
            video.style.display = 'block';
            holder.style.display = 'none';
            circle.classList.add('scanning');
            btn.textContent = 'Ambil Foto';
            setStatus('Posisikan wajah di tengah lingkaran, lalu klik "Ambil Foto".');
        } catch (err) {
            console.error(err);
            setStatus(err.name === 'NotAllowedError'
                ? 'Akses kamera ditolak. Izinkan kamera di pengaturan browser.'
                : 'Kamera / model tidak bisa dimuat. Coba muat ulang halaman.', 'error');
        } finally {
            btn.disabled = false;
        }
    }

    async function detectOnce() {
        // Pakai detectAllFaces supaya bisa menolak frame yang berisi lebih dari 1 wajah
        const results = await faceapi
            .detectAllFaces(video, detectorOptions)
            .withFaceLandmarks()
            .withFaceDescriptors();

        if (results.length === 0) throw new Error('Wajah tidak terdeteksi. Pastikan pencahayaan cukup.');
        if (results.length > 1) throw new Error('Terdeteksi lebih dari satu wajah. Pastikan hanya Anda di kamera.');

        const r = results[0];
        const box = r.detection.box;
        if (box.width < video.videoWidth * 0.2) throw new Error('Wajah terlalu jauh. Dekatkan wajah ke kamera.');

        return r.descriptor;
    }

    function snapshot() {
        const size = Math.min(video.videoWidth, video.videoHeight);
        const sx = (video.videoWidth - size) / 2;
        const sy = (video.videoHeight - size) / 2;
        canvas.getContext('2d').drawImage(video, sx, sy, size, size, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.85);
    }

    async function capture() {
        btn.disabled = true;
        try {
            const samples = [];
            for (let i = 0; i < SAMPLES; i++) {
                setStatus(`Memindai wajah... (${i + 1}/${SAMPLES})`);
                samples.push(await detectOnce());
                await sleep(300);
            }

            // Rata-rata beberapa sampel -> descriptor lebih stabil
            const avg = new Array(128).fill(0);
            samples.forEach(d => d.forEach((v, i) => avg[i] += v / samples.length));

            inDesc.value = JSON.stringify(avg.map(v => +v.toFixed(6)));
            inImg.value = snapshot();

            stream.getTracks().forEach(t => t.stop());
            setStatus('Wajah terdeteksi. Memverifikasi...', 'ok');
            btn.textContent = 'Memverifikasi...';
            form.submit();
        } catch (err) {
            setStatus(err.message, 'error');
            btn.disabled = false;
        }
    }

    btn.addEventListener('click', () => stream ? capture() : startCamera());
})();
</script>
@endpush

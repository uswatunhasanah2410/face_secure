{{--
    Pemakaian:
    <x-result-modal id="modal-success" type="success" title="Registrasi Berhasil"
        message="Akun Anda berhasil dibuat. Silakan login untuk melanjutkan."
        button="Login Sekarang" :href="route('login')" />

    <x-result-modal id="modal-failed" type="failed" title="Registrasi Gagal"
        message="..." button="Coba Lagi" />     // tanpa href = tombol menutup modal

    Buka/tutup via JS: openModal('modal-success') / closeModal('modal-success')
    Tampil langsung saat load: tambahkan :show="true"
--}}
@props([
    'id',
    'type' => 'success',
    'title' => '',
    'message' => '',
    'button' => 'OK',
    'href' => null,
    'show' => false,
])

<div class="modal-backdrop" id="{{ $id }}" style="{{ $show ? '' : 'display:none' }}">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
        <button type="button" class="modal-close" onclick="closeModal('{{ $id }}')" aria-label="Tutup">&times;</button>

        <h3 id="{{ $id }}-title">{{ $title }}</h3>

        <div class="modal-icon {{ $type }}">
            @if ($type === 'success')
                <svg viewBox="0 0 24 24"><polyline points="5 12.5 10 17 19 7.5"/></svg>
            @else
                <svg viewBox="0 0 24 24"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
            @endif
        </div>

        <p>{!! $message !!}</p>

        @if ($href)
            <a href="{{ $href }}" class="btn-outline">{{ $button }}</a>
        @else
            <button type="button" class="btn-outline" onclick="closeModal('{{ $id }}')">{{ $button }}</button>
        @endif
    </div>
</div>

@once
    @push('scripts')
    <script>
        function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop').forEach(m => m.style.display = 'none');
        });
    </script>
    @endpush
@endonce

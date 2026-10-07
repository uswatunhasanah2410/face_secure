{{--
    Pemakaian:
    <x-stepper :steps="['Data akun', 'Verifikasi email', 'Verifikasi wajah']" :current="2" />
    <x-stepper :steps="[...]" :current="3" :failed="true" />      // step aktif merah
    <x-stepper :steps="[...]" :current="4" />                     // semua selesai (hijau)
--}}
@props(['steps' => [], 'current' => 1, 'failed' => false])

<div class="stepper" style="grid-template-columns: repeat({{ count($steps) }}, 1fr);">
    @foreach ($steps as $i => $label)
        @php
            $n = $i + 1;
            $state = $n < $current ? 'done' : ($n == $current ? ($failed ? 'failed' : 'active') : '');
        @endphp
        <div class="stepper-item {{ $state }}">
            <div class="bar"></div>
            <div class="label"><span class="num">{{ $n }}</span>{{ $label }}</div>
        </div>
    @endforeach
</div>

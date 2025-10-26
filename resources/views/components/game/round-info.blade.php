{{-- Información de ronda --}}
@props([
    'current' => 1,
    'total' => 10,
    'label' => 'Ronda'
])

<div {{ $attributes->merge(['class' => 'text-center mb-8']) }}>
    <p class="text-lg text-gray-600">
        {{ $label }} <strong id="current-round" class="text-blue-600">{{ $current }}</strong>
        de <strong id="total-rounds" class="text-blue-600">{{ $total }}</strong>
    </p>
</div>

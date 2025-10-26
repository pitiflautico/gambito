{{-- Loading state genérico para todos los juegos --}}
@props([
    'emoji' => '⏳',
    'message' => 'Cargando...',
    'roomCode' => null
])

<div {{ $attributes->merge(['class' => 'text-center']) }}>
    <div class="mb-6">
        <span class="text-6xl">{{ $emoji }}</span>
    </div>
    <h2 class="text-2xl font-bold text-gray-800 mb-4">{{ $message }}</h2>
    @if($roomCode)
        <p class="text-gray-600">
            Sala: <strong class="text-gray-900">{{ $roomCode }}</strong>
        </p>
    @endif
</div>

{{-- Indicador de jugador bloqueado --}}
@props([
    'message' => 'Ya has respondido',
    'icon' => '🔒'
])

<div {{ $attributes->merge(['class' => 'bg-gray-100 border-2 border-gray-300 rounded-lg p-8 text-center']) }}>
    <div class="text-6xl mb-4">{{ $icon }}</div>
    <p class="text-xl font-medium text-gray-700">{{ $message }}</p>
    <p class="text-sm text-gray-500 mt-2">Esperando a los demás jugadores...</p>
</div>

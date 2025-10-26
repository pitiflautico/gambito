{{-- Countdown genérico --}}
@props([
    'seconds' => 3,
    'message' => 'Comenzando en...',
    'size' => 'large' // 'small', 'medium', 'large'
])

<div {{ $attributes->merge(['class' => 'text-center countdown-container']) }}>
    <p class="
        @if($size === 'small') text-lg
        @elseif($size === 'medium') text-2xl
        @else text-4xl
        @endif
        font-bold text-gray-800 mb-4
    ">
        {{ $message }}
    </p>
    <div class="
        countdown-number
        @if($size === 'small') text-4xl
        @elseif($size === 'medium') text-6xl
        @else text-8xl
        @endif
        font-bold text-blue-600
    ">
        <span class="animate-pulse">{{ $seconds }}</span>
    </div>
</div>

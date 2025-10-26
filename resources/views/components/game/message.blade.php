{{-- Mensaje temporal --}}
@props([
    'type' => 'info', // 'info', 'success', 'error', 'warning'
    'message' => '',
    'icon' => null,
    'dismissible' => false
])

@php
$colors = [
    'info' => 'bg-blue-100 border-blue-400 text-blue-700',
    'success' => 'bg-green-100 border-green-400 text-green-700',
    'error' => 'bg-red-100 border-red-400 text-red-700',
    'warning' => 'bg-yellow-100 border-yellow-400 text-yellow-700',
];

$icons = [
    'info' => 'ℹ️',
    'success' => '✅',
    'error' => '❌',
    'warning' => '⚠️',
];
@endphp

<div {{ $attributes->merge(['class' => "border-l-4 p-4 {$colors[$type]} rounded-r-lg"]) }} role="alert">
    <div class="flex items-center">
        @if($icon || isset($icons[$type]))
            <span class="text-2xl mr-3">{{ $icon ?? $icons[$type] }}</span>
        @endif
        <div class="flex-1">
            <p class="font-medium">{{ $message }}</p>
        </div>
        @if($dismissible)
            <button onclick="this.parentElement.parentElement.remove()" class="ml-3 text-xl font-bold">
                ×
            </button>
        @endif
    </div>
</div>

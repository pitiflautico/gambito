{{--
    Componente para mostrar el score de un jugador.

    Se actualiza automáticamente cuando llega el evento PlayerScoreUpdatedEvent.
    BaseGameClient busca elementos con id="player-score-{playerId}" y los actualiza.
--}}
@props([
    'playerId',
    'initialScore' => 0,
    'playerName' => null,
    'showName' => true,
    'size' => 'medium', // 'small', 'medium', 'large'
])

<div {{ $attributes->merge(['class' => 'player-score-container']) }}>
    @if($showName && $playerName)
        <span class="
            player-name
            @if($size === 'small') text-xs
            @elseif($size === 'large') text-lg font-semibold
            @else text-sm
            @endif
            text-gray-700
        ">
            {{ $playerName }}
        </span>
    @endif

    <span
        id="player-score-{{ $playerId }}"
        class="
            player-score
            @if($size === 'small') text-sm
            @elseif($size === 'large') text-2xl font-bold
            @else text-lg font-semibold
            @endif
            text-blue-600
            transition-all duration-300
        "
    >
        {{ $initialScore }}
    </span>

    @if($size !== 'small')
        <span class="text-xs text-gray-500">pts</span>
    @endif
</div>

<style>
    .player-score-container {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    /* Animación cuando aumenta el score */
    .player-score.score-increase {
        animation: scoreIncrease 0.5s ease-out;
        color: #10b981; /* green-500 */
    }

    @keyframes scoreIncrease {
        0% {
            transform: scale(1);
        }
        50% {
            transform: scale(1.3);
            color: #10b981;
        }
        100% {
            transform: scale(1);
        }
    }

    /* Animación cuando disminuye el score */
    .player-score.score-decrease {
        animation: scoreDecrease 0.5s ease-out;
        color: #ef4444; /* red-500 */
    }

    @keyframes scoreDecrease {
        0% {
            transform: scale(1);
        }
        50% {
            transform: scale(0.8);
            color: #ef4444;
        }
        100% {
            transform: scale(1);
        }
    }
</style>

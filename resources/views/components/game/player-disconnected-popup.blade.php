{{--
    Player Disconnected Popup Component

    Shows when a player disconnects during the game.
    Automatically controlled by PlayerDisconnectedEvent and PlayerReconnectedEvent.

    Usage:
    <x-game.player-disconnected-popup />
--}}

<div id="player-disconnected-popup"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black bg-opacity-60 backdrop-blur-sm"
     style="display: none;">

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl max-w-md w-full mx-4 transform transition-all">

        {{-- Header --}}
        <div class="bg-orange-500 dark:bg-orange-600 px-6 py-4 rounded-t-lg">
            <div class="flex items-center gap-3">
                <svg class="w-8 h-8 text-white animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 class="text-xl font-bold text-white">Jugador Desconectado</h3>
            </div>
        </div>

        {{-- Body --}}
        <div class="px-6 py-8">
            <div class="text-center space-y-4">

                {{-- Player Info --}}
                <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4 border-2 border-orange-200 dark:border-orange-700">
                    <p class="text-gray-700 dark:text-gray-300 text-sm mb-2">Se ha desconectado:</p>
                    <p id="disconnected-player-name" class="text-2xl font-bold text-orange-600 dark:text-orange-400">
                        Jugador
                    </p>
                </div>

                {{-- Status Message --}}
                <div class="space-y-2">
                    <p class="text-gray-600 dark:text-gray-400">
                        El juego está pausado hasta que se reconecte.
                    </p>

                    {{-- Waiting Animation --}}
                    <div class="flex items-center justify-center gap-2 mt-4">
                        <div class="flex gap-1">
                            <span class="w-2 h-2 bg-orange-500 rounded-full animate-bounce" style="animation-delay: 0ms;"></span>
                            <span class="w-2 h-2 bg-orange-500 rounded-full animate-bounce" style="animation-delay: 150ms;"></span>
                            <span class="w-2 h-2 bg-orange-500 rounded-full animate-bounce" style="animation-delay: 300ms;"></span>
                        </div>
                        <span class="text-sm text-gray-500 dark:text-gray-400">Esperando reconexión</span>
                    </div>
                </div>

                {{-- Game Info --}}
                <div class="text-xs text-gray-500 dark:text-gray-500 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p>Ronda actual: <span id="disconnected-round-info" class="font-semibold">-</span></p>
                    <p class="mt-1">Fase: <span id="disconnected-phase-info" class="font-semibold">-</span></p>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
/**
 * Player Disconnected Popup Controller
 *
 * Listens to PlayerDisconnectedEvent and PlayerReconnectedEvent
 * and shows/hides the popup accordingly.
 */
(function() {
    const popup = document.getElementById('player-disconnected-popup');
    const playerNameEl = document.getElementById('disconnected-player-name');
    const roundInfoEl = document.getElementById('disconnected-round-info');
    const phaseInfoEl = document.getElementById('disconnected-phase-info');

    if (!popup) return;

    /**
     * Show popup with player info
     */
    function showDisconnectedPopup(data) {
        playerNameEl.textContent = data.player_name || 'Jugador';
        roundInfoEl.textContent = data.current_round || 'N/A';
        phaseInfoEl.textContent = data.game_phase || 'unknown';

        popup.style.display = 'flex';
        popup.classList.remove('hidden');
    }

    /**
     * Hide popup
     */
    function hideDisconnectedPopup(data) {
        popup.style.display = 'none';
        popup.classList.add('hidden');
    }

    /**
     * Listen to events via window (BaseGameClient will dispatch them)
     */
    window.addEventListener('game:player:disconnected', (event) => {
        showDisconnectedPopup(event.detail);
    });

    window.addEventListener('game:player:reconnected', (event) => {
        hideDisconnectedPopup(event.detail);
    });

    console.log('[DisconnectedPopup] Component initialized');
})();
</script>
@endpush

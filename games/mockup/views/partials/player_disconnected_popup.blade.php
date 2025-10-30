<!-- Player Disconnected Popup Template -->
<div id="player-disconnected-popup" class="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50" style="display: none;">
    <div class="bg-gray-800 rounded-lg p-8 max-w-md w-full mx-4 shadow-2xl border-4 border-red-500">
        <!-- Header -->
        <div class="text-center mb-6">
            <div class="text-6xl mb-4">⚠️</div>
            <h2 class="text-3xl font-bold text-red-400 mb-2">
                Jugador Desconectado
            </h2>
        </div>

        <!-- Disconnected Player Info -->
        <div class="bg-gray-900 rounded-lg p-6 mb-6 text-center">
            <p class="text-gray-400 mb-2">El jugador</p>
            <p id="disconnected-player-name" class="text-2xl font-bold text-white mb-3"></p>
            <p class="text-gray-400 text-sm">se ha desconectado</p>
        </div>

        <!-- Status Message -->
        <div class="bg-yellow-900/30 border border-yellow-500 rounded-lg p-4 mb-6">
            <p class="text-yellow-200 text-center text-sm">
                ⏸️ El juego está pausado<br>
                <span class="text-xs text-yellow-300">Esperando reconexión...</span>
            </p>
        </div>

        <!-- Info -->
        <div class="text-center">
            <p class="text-gray-500 text-xs">
                Los timers están pausados. El juego se reanudará automáticamente cuando el jugador se reconecte.
            </p>
        </div>
    </div>
</div>

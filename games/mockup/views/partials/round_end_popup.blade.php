<!-- Round End Popup Template -->
<div id="round-end-popup" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50" style="display: none;">
    <div class="bg-gray-800 rounded-lg p-8 max-w-2xl w-full mx-4 shadow-2xl border-4 border-yellow-500">
        <!-- Header -->
        <div class="text-center mb-6">
            <h2 class="text-4xl font-bold text-yellow-400 mb-2">
                🏁 Ronda <span id="popup-round-number">1</span> Finalizada
            </h2>
            <p class="text-gray-400 text-lg" id="popup-subtitle">Resultados de la ronda</p>
        </div>

        <!-- Results Section -->
        <div id="popup-results" class="mb-6">
            <!-- Los juegos específicos pueden sobrescribir esto -->
            <div class="bg-gray-900 rounded-lg p-6">
                <h3 class="text-xl font-bold text-white mb-4 text-center">Puntuaciones</h3>
                <div id="popup-scores-list" class="space-y-2">
                    <!-- Scores will be populated dynamically -->
                </div>
            </div>
        </div>

        <!-- Timer Section -->
        <div class="text-center bg-gray-900 rounded-lg p-6">
            <p id="popup-timer-message" class="text-sm text-gray-400 mb-2">Siguiente ronda en</p>
            <p id="popup-timer" class="text-6xl font-bold text-green-400 font-mono">3</p>
        </div>
    </div>
</div>

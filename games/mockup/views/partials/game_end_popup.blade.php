<!-- Game End Popup Template -->
<div id="game-end-popup" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50" style="display: none;">
    <div class="bg-gray-800 rounded-lg p-8 max-w-2xl w-full mx-4 shadow-2xl border-4 border-green-500">
        <!-- Header -->
        <div class="text-center mb-6">
            <h2 class="text-5xl font-bold text-green-400 mb-2">
                🏆 ¡Partida Finalizada!
            </h2>
            <p class="text-gray-400 text-lg" id="game-end-subtitle">Resultados finales</p>
        </div>

        <!-- Winner Section -->
        <div id="game-end-winner" class="mb-6 text-center bg-gradient-to-r from-yellow-600 to-yellow-500 rounded-lg p-6">
            <h3 class="text-3xl font-bold text-white mb-2">🥇 Ganador</h3>
            <p id="winner-name" class="text-4xl font-bold text-yellow-100"></p>
            <p id="winner-score" class="text-2xl text-yellow-200 mt-2"></p>
        </div>

        <!-- Final Rankings Section -->
        <div id="game-end-rankings" class="mb-6">
            <div class="bg-gray-900 rounded-lg p-6">
                <h3 class="text-2xl font-bold text-white mb-4 text-center">Clasificación Final</h3>
                <div id="game-end-rankings-list" class="space-y-3">
                    <!-- Rankings will be populated dynamically -->
                </div>
            </div>
        </div>

        <!-- Back to Lobby Button -->
        <div class="text-center">
            <button id="back-to-lobby-btn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 px-8 rounded-lg text-xl transition-colors duration-200 shadow-lg">
                ← Volver al Lobby
            </button>
        </div>
    </div>
</div>

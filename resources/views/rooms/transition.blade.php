<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            🎮 Preparando {{ $gameName }} - Sala: {{ $code }}
        </h2>
    </x-slot>

<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            <!-- Header -->
            <div class="bg-indigo-600 px-6 py-4">
                <h4 class="text-2xl font-bold text-white">🎮 {{ $gameName }}</h4>
                <p class="text-indigo-200 text-sm mt-1">Sala: {{ $code }}</p>
            </div>

            <!-- Body -->
            <div class="px-6 py-12 text-center">
                <!-- Estado: Esperando jugadores -->
                <div id="waiting-state">
                    <!-- Spinner -->
                    <div class="flex justify-center mb-6">
                        <div class="animate-spin rounded-full h-16 w-16 border-b-2 border-indigo-600"></div>
                    </div>

                    <h3 class="text-2xl font-bold text-gray-800 mb-3">Esperando a todos los jugadores...</h3>
                    <p class="text-gray-600 mb-8">
                        <span id="connected-count" class="font-bold text-indigo-600">0</span> /
                        <span id="total-count" class="font-bold">{{ $totalPlayers }}</span> conectados
                    </p>

                    <!-- Lista de jugadores -->
                    <div class="max-w-md mx-auto">
                        <div id="players-list" class="space-y-2">
                            @foreach($expectedPlayers as $player)
                                <div data-player-id="{{ $player['id'] }}"
                                     class="flex justify-between items-center bg-gray-50 px-4 py-3 rounded-lg border border-gray-200">
                                    <span class="text-gray-800 font-medium">{{ $player['name'] }}</span>
                                    <span class="player-status px-3 py-1 text-xs font-semibold rounded-full bg-gray-200 text-gray-600">
                                        Esperando...
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <!-- Estado: Countdown -->
                <div id="countdown-state" class="hidden">
                    <div class="text-9xl font-bold text-indigo-600 mb-6" id="countdown-number">3</div>
                    <h3 class="text-2xl font-semibold text-gray-700" id="countdown-message">El juego comenzará en...</h3>
                </div>

                <!-- Estado: Inicializando -->
                <div id="initializing-state" class="hidden">
                    <div class="flex justify-center mb-6">
                        <div class="animate-spin rounded-full h-16 w-16 border-b-2 border-green-600"></div>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-3">Inicializando juego...</h3>
                    <p class="text-gray-600">Preparando el motor del juego</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="module">
// Variables globales
const roomCode = '{{ $code }}';
const totalPlayers = {{ $totalPlayers }};
const expectedPlayers = @json($expectedPlayers);
let connectedUsers = [];
let allConnectedNotified = false;
let timing = null;

console.log('🎮 [Transition] Initializing...', {
    roomCode,
    totalPlayers,
    expectedPlayers
});

// Inicializar TimingModule cuando esté disponible
function initializeTimingModule() {
    if (typeof window.TimingModule === 'undefined') {
        console.log('⏳ [Transition] Waiting for TimingModule...');
        setTimeout(initializeTimingModule, 100);
        return;
    }

    timing = new window.TimingModule();
    console.log('✅ [Transition] TimingModule initialized');
}

initializeTimingModule();

/**
 * Inicializar Presence Channel
 */
function initializePresenceChannel() {
    if (typeof window.Echo === 'undefined') {
        console.log('⏳ [Transition] Waiting for Echo...');
        setTimeout(initializePresenceChannel, 100);
        return;
    }

    console.log('📡 [Transition] Connecting to Presence Channel...');

    const presenceChannel = window.Echo.join(`room.${roomCode}`);

    // Usuarios actualmente conectados
    presenceChannel.here((users) => {
        console.log('👥 [Transition] Users here:', users.length);
        connectedUsers = users;
        updatePlayerStatus(users);
        checkAllConnected();
    });

    // Usuario se unió
    presenceChannel.joining((user) => {
        console.log('✅ [Transition] User joining:', user.name);
        connectedUsers.push(user);
        updatePlayerStatus(connectedUsers);
        checkAllConnected();
    });

    // Usuario se fue
    presenceChannel.leaving((user) => {
        console.log('❌ [Transition] User leaving:', user.name);
        connectedUsers = connectedUsers.filter(u => u.id !== user.id);
        updatePlayerStatus(connectedUsers);
        allConnectedNotified = false; // Reset para volver a verificar
    });

    console.log('✅ [Transition] Presence Channel initialized');
}

/**
 * Actualizar estado visual de jugadores conectados
 */
function updatePlayerStatus(users) {
    const connectedUserIds = users.map(u => u.id);
    const connectedCount = connectedUserIds.length;

    // Actualizar contador
    document.getElementById('connected-count').textContent = connectedCount;

    // Actualizar badges
    expectedPlayers.forEach(player => {
        const playerElement = document.querySelector(`[data-player-id="${player.id}"]`);
        if (!playerElement) return;

        const badge = playerElement.querySelector('.player-status');
        const isConnected = connectedUserIds.includes(player.user_id);

        if (isConnected) {
            badge.textContent = 'Conectado ✓';
            badge.classList.remove('bg-gray-200', 'text-gray-600');
            badge.classList.add('bg-green-100', 'text-green-700');
        } else {
            badge.textContent = 'Esperando...';
            badge.classList.remove('bg-green-100', 'text-green-700');
            badge.classList.add('bg-gray-200', 'text-gray-600');
        }
    });
}

/**
 * Verificar si todos los jugadores están conectados
 */
function checkAllConnected() {
    const connected = connectedUsers.length;
    const total = totalPlayers;

    console.log(`📊 [Transition] Players status: ${connected}/${total}`);

    if (connected >= total && !allConnectedNotified) {
        console.log('✅ [Transition] All players connected! Notifying server...');
        allConnectedNotified = true;

        // Notificar al servidor que todos están listos
        fetch(`/api/rooms/${roomCode}/ready`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        })
        .then(response => response.json())
        .then(data => {
            console.log('✅ [Transition] Server notified, countdown will begin', data);
        })
        .catch(error => {
            console.error('❌ [Transition] Error notifying server:', error);
            allConnectedNotified = false; // Permitir reintentar
        });
    }
}

/**
 * Inicializar listeners de WebSocket para eventos del juego
 */
function initializeGameEvents() {
    if (typeof window.Echo === 'undefined') {
        console.log('⏳ [Transition] Waiting for Echo (game events)...');
        setTimeout(initializeGameEvents, 100);
        return;
    }

    const channel = window.Echo.channel(`room.${roomCode}`);

    // Evento: Countdown iniciado (desde backend)
    channel.listen('.game.countdown', (data) => {
        console.log('⏰ [Transition] Countdown event received:', data);

        // Asegurar que TimingModule está inicializado
        if (!timing) {
            console.error('❌ [Transition] TimingModule not initialized yet!');
            return;
        }

        // Ocultar estado de espera
        document.getElementById('waiting-state').classList.add('hidden');
        document.getElementById('countdown-state').classList.remove('hidden');

        const countdownElement = document.getElementById('countdown-number');
        const messageElement = document.getElementById('countdown-message');

        // Usar TimingModule con countdown sincronizado por timestamps
        // Este es el método que usan Fortnite, CS:GO, etc.
        timing.handleCountdownEvent(
            data,
            countdownElement,
            () => {
                // Callback cuando termina el countdown
                messageElement.textContent = '¡Comenzando!';
                console.log('⏰ [Transition] Countdown finished, initializing engine...');

                // Llamar al endpoint para inicializar el engine
                fetch(`/api/rooms/${roomCode}/initialize-engine`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    console.log('✅ [Transition] Engine initialization requested', data);
                })
                .catch(error => {
                    console.error('❌ [Transition] Error initializing engine:', error);
                });
            },
            'game-start'
        );
    });

    // Evento: Juego inicializado
    channel.listen('.game.initialized', (data) => {
        console.log('🎮 [Transition] Game initialized, loading game view...', data);
        showInitializing();

        // Esperar un momento antes de recargar para que el estado se actualice
        setTimeout(() => {
            window.location.replace(`/rooms/${roomCode}`);
        }, 1000);
    });

    console.log('✅ [Transition] Game event listeners registered');
}

/**
 * Mostrar estado de inicialización
 */
function showInitializing() {
    document.getElementById('countdown-state').classList.add('hidden');
    document.getElementById('initializing-state').classList.remove('hidden');
}

// Inicializar
document.addEventListener('DOMContentLoaded', () => {
    initializePresenceChannel();
    initializeGameEvents();
});
</script>
</x-app-layout>

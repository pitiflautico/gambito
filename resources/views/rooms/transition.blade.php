<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            🎮 Preparando {{ $gameName }} - Sala: {{ $code }}
        </h2>
    </x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">🎮 {{ $gameName }}</h4>
                    <small>Sala: {{ $code }}</small>
                </div>

                <div class="card-body text-center py-5">
                    <!-- Estado: Esperando jugadores -->
                    <div id="waiting-state">
                        <div class="spinner-border text-primary mb-4" role="status" style="width: 4rem; height: 4rem;">
                            <span class="visually-hidden">Esperando...</span>
                        </div>
                        <h3 class="mb-3">Esperando a todos los jugadores...</h3>
                        <p class="text-muted mb-4">
                            <span id="connected-count">0</span> / <span id="total-count">{{ $totalPlayers }}</span> conectados
                        </p>

                        <!-- Lista de jugadores esperados -->
                        <div class="row justify-content-center">
                            <div class="col-md-6">
                                <div class="list-group" id="players-list">
                                    @foreach($expectedPlayers as $player)
                                        <div class="list-group-item d-flex justify-content-between align-items-center" data-player-id="{{ $player['id'] }}">
                                            <span>{{ $player['name'] }}</span>
                                            <span class="badge bg-secondary player-status">Esperando...</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Estado: Countdown -->
                    <div id="countdown-state" class="d-none">
                        <h1 class="display-1 mb-4" id="countdown-number">3</h1>
                        <h3 id="countdown-message">El juego comenzará en...</h3>
                    </div>

                    <!-- Estado: Inicializando -->
                    <div id="initializing-state" class="d-none">
                        <div class="spinner-border text-success mb-4" role="status" style="width: 4rem; height: 4rem;">
                            <span class="visually-hidden">Inicializando...</span>
                        </div>
                        <h3 class="mb-3">Inicializando juego...</h3>
                        <p class="text-muted">Preparando el motor del juego</p>
                    </div>
                </div>
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
            badge.classList.remove('bg-secondary');
            badge.classList.add('bg-success');
        } else {
            badge.textContent = 'Esperando...';
            badge.classList.remove('bg-success');
            badge.classList.add('bg-secondary');
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
        document.getElementById('waiting-state').classList.add('d-none');
        document.getElementById('countdown-state').classList.remove('d-none');

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
    document.getElementById('countdown-state').classList.add('d-none');
    document.getElementById('initializing-state').classList.remove('d-none');
}

// Inicializar
document.addEventListener('DOMContentLoaded', () => {
    initializePresenceChannel();
    initializeGameEvents();
});
</script>
</x-app-layout>

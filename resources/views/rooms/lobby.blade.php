<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Sala: {{ $room->code }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <!-- Información del Juego -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-2xl font-bold mb-2">{{ $room->game->name }}</h3>
                    <p class="text-gray-600">{{ $room->game->description }}</p>
                </div>
            </div>

            <!-- Panel de Configuración de Equipos (Solo si está activado) -->
            @if(isset($room->game_settings['play_with_teams']) && $room->game_settings['play_with_teams'])
            <div class="bg-gradient-to-r from-purple-50 to-blue-50 border-2 border-purple-200 overflow-hidden shadow-lg sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center space-x-3">
                            <span class="text-3xl">🏆</span>
                            <div>
                                <h3 class="text-xl font-bold text-purple-900">Modo Equipos</h3>
                                <p class="text-sm text-purple-700">Configura los equipos antes de iniciar</p>
                            </div>
                        </div>
                        @if($isMaster)
                        <span class="px-3 py-1 bg-purple-600 text-white text-xs font-bold rounded-full">
                            MASTER
                        </span>
                        @endif
                    </div>

                    @if($isMaster)
                    <!-- Controles del Master -->
                    <div class="space-y-4">
                        <!-- Número de Equipos -->
                        <div class="bg-white rounded-lg p-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Número de Equipos
                            </label>
                            <div class="flex items-center space-x-2">
                                <button
                                    onclick="changeTeamCount(-1)"
                                    class="px-3 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg font-bold"
                                >
                                    −
                                </button>
                                <input
                                    type="number"
                                    id="team-count"
                                    value="2"
                                    min="2"
                                    max="4"
                                    readonly
                                    class="w-16 text-center text-lg font-bold border-2 border-purple-300 rounded-lg"
                                >
                                <button
                                    onclick="changeTeamCount(1)"
                                    class="px-3 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg font-bold"
                                >
                                    +
                                </button>
                                <button
                                    onclick="initializeTeams()"
                                    class="ml-auto px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium"
                                >
                                    Crear Equipos
                                </button>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Crea los equipos para empezar a asignar jugadores</p>
                        </div>

                        <!-- Modo de Asignación -->
                        <div class="bg-white rounded-lg p-4">
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                Método de Asignación
                            </label>
                            <div class="space-y-2">
                                <label class="flex items-start p-3 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-purple-400">
                                    <input
                                        type="radio"
                                        name="assignment_mode"
                                        value="manual"
                                        checked
                                        class="mt-1 mr-3 text-purple-600"
                                    >
                                    <div>
                                        <div class="font-medium">Manual</div>
                                        <div class="text-xs text-gray-500">Arrastra jugadores a los equipos</div>
                                    </div>
                                </label>
                                <label class="flex items-start p-3 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-purple-400">
                                    <input
                                        type="radio"
                                        name="assignment_mode"
                                        value="random"
                                        class="mt-1 mr-3 text-purple-600"
                                    >
                                    <div>
                                        <div class="font-medium">Aleatorio Balanceado</div>
                                        <div class="text-xs text-gray-500">Distribución automática equitativa</div>
                                    </div>
                                </label>
                                <label class="flex items-start p-3 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-purple-400">
                                    <input
                                        type="radio"
                                        name="assignment_mode"
                                        value="self"
                                        class="mt-1 mr-3 text-purple-600"
                                        onchange="toggleSelfSelection(this.checked)"
                                    >
                                    <div>
                                        <div class="font-medium">Auto-selección</div>
                                        <div class="text-xs text-gray-500">Cada jugador elige su equipo</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                    @else
                    <!-- Vista para Jugadores -->
                    <div class="bg-white rounded-lg p-4 text-center">
                        <p class="text-gray-600">
                            <span class="font-semibold">{{ $room->master->name }}</span> está configurando los equipos...
                        </p>
                        <p class="text-sm text-gray-500 mt-2">
                            Espera a que termine la configuración para ver los equipos
                        </p>
                    </div>
                    @endif

                    <!-- Área de Equipos (se mostrará cuando se inicialicen) -->
                    <div id="teams-area" class="mt-6 hidden">
                        <h4 class="text-lg font-semibold mb-4">Equipos</h4>
                        <div id="teams-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Los equipos se generarán dinámicamente aquí -->
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Jugadores -->
                <div class="lg:col-span-2">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-semibold mb-4">
                                Jugadores ({{ $stats['players'] }}/{{ $room->game->max_players }})
                            </h3>

                            @if($room->match && $room->match->players->count() > 0)
                                <div class="space-y-2">
                                    @foreach($room->match->players as $player)
                                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg" data-player-id="{{ $player->id }}">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold">
                                                    {{ strtoupper(substr($player->name, 0, 1)) }}
                                                </div>
                                                <div>
                                                    <p class="font-medium">{{ $player->name }}</p>
                                                    @if($player->role)
                                                        <p class="text-xs text-gray-500">{{ $player->role }}</p>
                                                    @endif
                                                    <p class="text-xs text-purple-600 font-medium player-team-badge" data-player-id="{{ $player->id }}">
                                                        <!-- El equipo se mostrará aquí vía JS -->
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                @if($player->is_connected)
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        ● Conectado
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                        ○ Desconectado
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-center py-8 text-gray-500">
                                    <p>Esperando jugadores...</p>
                                    <p class="text-sm mt-2">Comparte el código o escanea el QR</p>
                                </div>
                            @endif

                            <!-- Información de Jugadores Mínimos -->
                            <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                                <p class="text-sm text-blue-800">
                                    Mínimo {{ $room->game->min_players }} jugadores para comenzar
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Panel de Control del Master -->
                <div class="space-y-6">
                    <!-- Código de la Sala -->
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-sm font-medium text-gray-700 mb-2">Código de la Sala</h3>
                            <div class="flex items-center justify-center p-4 bg-gray-900 rounded-lg">
                                <p class="text-4xl font-bold text-white tracking-wider">{{ $room->code }}</p>
                            </div>

                            <!-- URL de Invitación -->
                            <div class="mt-4">
                                <label class="text-xs text-gray-600">URL de invitación:</label>
                                <div class="flex mt-1">
                                    <input
                                        type="text"
                                        value="{{ $inviteUrl }}"
                                        readonly
                                        class="flex-1 text-sm px-3 py-2 border border-gray-300 rounded-l-md bg-gray-50"
                                        id="invite-url"
                                    >
                                    <button
                                        onclick="copyToClipboard()"
                                        class="px-4 py-2 bg-gray-700 text-white rounded-r-md hover:bg-gray-800 text-sm"
                                    >
                                        Copiar
                                    </button>
                                </div>
                            </div>

                            <!-- Código QR -->
                            <div class="mt-4 text-center">
                                <div id="qr-container" class="mx-auto w-48 h-48 border-4 border-gray-200 rounded-lg flex items-center justify-center bg-gray-50">
                                    <div class="text-gray-400 text-sm">
                                        <svg class="w-8 h-8 mx-auto mb-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                        </svg>
                                        <span>Cargando QR...</span>
                                    </div>
                                </div>
                                <img
                                    id="qr-image"
                                    data-src="{{ $qrCodeUrl }}"
                                    alt="QR Code"
                                    class="hidden mx-auto w-48 h-48 border-4 border-gray-200 rounded-lg"
                                    style="display: none;"
                                >
                                <p class="text-xs text-gray-500 mt-2">Escanea para unirte</p>
                            </div>
                        </div>
                    </div>

                    <!-- Botón Iniciar (Solo Master) -->
                    @if($isMaster)
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-sm font-medium text-gray-700 mb-3">Control de la Sala</h3>

                                @if($canStart['can_start'])
                                    <button
                                        onclick="startGame()"
                                        class="w-full px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 font-semibold"
                                    >
                                        🎮 Iniciar Partida
                                    </button>
                                @else
                                    <button
                                        disabled
                                        class="w-full px-6 py-3 bg-gray-300 text-gray-500 rounded-lg cursor-not-allowed font-semibold"
                                        title="{{ $canStart['reason'] }}"
                                    >
                                        🎮 Iniciar Partida
                                    </button>
                                    <p class="text-xs text-red-600 mt-2 text-center">{{ $canStart['reason'] }}</p>
                                @endif

                                <button
                                    onclick="closeRoom()"
                                    class="w-full mt-3 px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                                >
                                    ❌ Cerrar Sala
                                </button>
                            </div>
                        </div>
                    @else
                        <!-- Mensaje para jugadores no-master -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <div class="text-center">
                                    <div class="mb-3">
                                        <svg class="w-16 h-16 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">
                                        Esperando al organizador
                                    </h3>
                                    <p class="text-sm text-gray-600">
                                        {{ $room->master->name }} iniciará la partida cuando todos estén listos
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        // Lazy load QR code con timeout para evitar bloquear la página
        function loadQRCode() {
            const qrImage = document.getElementById('qr-image');
            const qrContainer = document.getElementById('qr-container');
            const qrUrl = qrImage.getAttribute('data-src');

            // Timeout de 5 segundos para cargar el QR
            const timeout = setTimeout(() => {
                console.error('QR code load timeout');
                qrContainer.innerHTML = `
                    <div class="text-red-500 text-sm p-4">
                        <svg class="w-8 h-8 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>QR no disponible</span>
                        <p class="text-xs mt-1">Usa el código o la URL</p>
                    </div>
                `;
            }, 5000);

            // Intentar cargar la imagen
            const img = new Image();
            img.onload = function() {
                clearTimeout(timeout);
                qrImage.src = qrUrl;
                qrImage.style.display = 'block';
                qrImage.classList.remove('hidden');
                qrContainer.style.display = 'none';
                console.log('QR code loaded successfully');
            };
            img.onerror = function() {
                clearTimeout(timeout);
                console.error('QR code failed to load');
                qrContainer.innerHTML = `
                    <div class="text-gray-500 text-sm p-4">
                        <svg class="w-8 h-8 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>QR no disponible</span>
                        <p class="text-xs mt-1">Usa el código o la URL</p>
                    </div>
                `;
            };
            img.src = qrUrl;
        }

        // Cargar QR cuando el DOM esté listo
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', loadQRCode);
        } else {
            loadQRCode();
        }

        function copyToClipboard() {
            const input = document.getElementById('invite-url');
            input.select();
            input.setSelectionRange(0, 99999); // Para móviles

            // Usar la API moderna de clipboard
            navigator.clipboard.writeText(input.value).then(function() {
                // Cambiar texto del botón temporalmente
                const button = event.target;
                const originalText = button.textContent;
                button.textContent = '✓ Copiado';
                button.classList.add('bg-green-600');
                button.classList.remove('bg-gray-700');

                setTimeout(function() {
                    button.textContent = originalText;
                    button.classList.remove('bg-green-600');
                    button.classList.add('bg-gray-700');
                }, 2000);
            }).catch(function(err) {
                // Fallback para navegadores antiguos
                document.execCommand('copy');
                alert('URL copiada al portapapeles');
            });
        }

        function startGame() {
            if (!confirm('¿Iniciar la partida?')) return;

            fetch('/rooms/{{ $room->code }}/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '/rooms/{{ $room->code }}';
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al iniciar la partida');
            });
        }

        function closeRoom() {
            if (!confirm('¿Cerrar la sala? Esto finalizará la partida.')) return;

            fetch('/rooms/{{ $room->code }}/close', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '/dashboard';
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al cerrar la sala');
            });
        }

        // WebSocket: Conectar al canal de la sala para actualizaciones en tiempo real
        function initializeWebSocket() {
            if (typeof window.Echo === 'undefined') {
                console.log('Echo not ready yet, waiting...');
                setTimeout(initializeWebSocket, 100);
                return;
            }

            console.log('Connecting to room channel: room.{{ $room->code }}');

            const channel = window.Echo.channel('room.{{ $room->code }}');

            // Evento: Jugador se unió
            channel.listen('.player.joined', (data) => {
                console.log('Player joined:', data);
                // Recargar página para mostrar nuevo jugador
                location.reload();
            });

            // Evento: Jugador salió
            channel.listen('.player.left', (data) => {
                console.log('Player left:', data);
                // Recargar página para actualizar lista
                location.reload();
            });

            // Evento: Partida iniciada
            channel.listen('.game.started', (data) => {
                console.log('Game started:', data);
                // Redirigir a la sala de juego
                window.location.href = '/rooms/{{ $room->code }}';
            });

            console.log('WebSocket listeners registered for lobby');
        }

        // Iniciar WebSocket cuando DOM esté listo
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeWebSocket);
        } else {
            initializeWebSocket();
        }

        // Polling de respaldo cada 10 segundos (por si WebSocket falla)
        let refreshInterval = setInterval(() => {
            fetch('/api/rooms/{{ $room->code }}/stats')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Si el número de jugadores cambió, recargar la página
                        const currentPlayers = {{ $stats['players'] }};
                        if (data.data.players !== currentPlayers) {
                            console.log('Player count changed via polling, reloading...');
                            location.reload();
                        }

                        // Si el estado de la sala cambió, redirigir
                        @if($room->status === App\Models\Room::STATUS_WAITING)
                            // Si la partida comenzó, redirigir a la sala activa
                            if (data.data.status === 'playing') {
                                console.log('Game started via polling, redirecting...');
                                window.location.href = '/rooms/{{ $room->code }}';
                            }
                        @endif
                    }
                })
                .catch(error => {
                    console.error('Error refreshing:', error);
                });
        }, 10000); // Cada 10 segundos (menos frecuente ya que tenemos WebSockets)

        // ========================================================================
        // FUNCIONES PARA GESTIÓN DE EQUIPOS
        // ========================================================================

        function changeTeamCount(delta) {
            const input = document.getElementById('team-count');
            let value = parseInt(input.value) + delta;
            if (value >= 2 && value <= 4) {
                input.value = value;
            }
        }

        function initializeTeams() {
            const count = parseInt(document.getElementById('team-count').value);

            // Llamar a API para crear equipos
            fetch('/api/rooms/{{ $room->code }}/teams/enable', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    num_teams: count
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mostrar área de equipos
                    document.getElementById('teams-area').classList.remove('hidden');
                    renderTeams(data.teams);
                } else {
                    alert('Error al crear equipos: ' + (data.message || 'Error desconocido'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al crear equipos');
            });
        }

        function renderTeams(teams) {
            const container = document.getElementById('teams-container');
            const colors = ['bg-red-100 border-red-300', 'bg-blue-100 border-blue-300', 'bg-green-100 border-green-300', 'bg-yellow-100 border-yellow-300'];

            container.innerHTML = teams.map((team, index) => `
                <div class="border-2 rounded-lg p-4 ${colors[index % colors.length]}">
                    <div class="flex items-center justify-between mb-3">
                        <h5 class="font-bold text-lg">${team.name}</h5>
                        <span class="text-sm font-medium px-2 py-1 bg-white rounded-full">${team.members.length} jugadores</span>
                    </div>
                    <div id="team-${team.id}" class="space-y-2 min-h-[100px]">
                        ${team.members.length === 0 ? '<p class="text-sm text-gray-500 text-center py-4">Sin jugadores</p>' : ''}
                        ${team.members.map(memberId => renderPlayerInTeam(memberId)).join('')}
                    </div>
                </div>
            `).join('');
        }

        function renderPlayerInTeam(playerId) {
            // Buscar el jugador en la lista
            const playerElements = document.querySelectorAll('[data-player-id]');
            for (let elem of playerElements) {
                if (elem.dataset.playerId == playerId) {
                    const name = elem.querySelector('.font-medium')?.textContent || 'Jugador';
                    return `
                        <div class="bg-white p-2 rounded flex items-center justify-between">
                            <span class="text-sm font-medium">${name}</span>
                            <button onclick="removeFromTeam(${playerId})" class="text-red-500 hover:text-red-700 text-xs">✕</button>
                        </div>
                    `;
                }
            }
            return '';
        }

        function removeFromTeam(playerId) {
            // Implementar lógica de remover jugador del equipo
            console.log('Remover jugador:', playerId);
        }

        function toggleSelfSelection(enabled) {
            // Implementar lógica para habilitar/deshabilitar auto-selección
            console.log('Auto-selección:', enabled);
        }

        // Actualizar badges de equipo en la lista de jugadores
        function updatePlayerTeamBadges() {
            @if(isset($room->game_settings['play_with_teams']) && $room->game_settings['play_with_teams'])
                fetch('/api/rooms/{{ $room->code }}/teams')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.teams) {
                            const teamColors = {
                                'team_1': 'bg-red-100 text-red-800',
                                'team_2': 'bg-blue-100 text-blue-800',
                                'team_3': 'bg-green-100 text-green-800',
                                'team_4': 'bg-yellow-100 text-yellow-800'
                            };

                            // Limpiar badges anteriores
                            document.querySelectorAll('.player-team-badge').forEach(badge => {
                                badge.textContent = '';
                                badge.className = 'text-xs text-purple-600 font-medium player-team-badge';
                            });

                            // Asignar badges según equipos
                            data.teams.forEach(team => {
                                team.members.forEach(playerId => {
                                    const badge = document.querySelector(`.player-team-badge[data-player-id="${playerId}"]`);
                                    if (badge) {
                                        badge.textContent = `🏆 ${team.name}`;
                                        badge.className = `text-xs font-medium px-2 py-0.5 rounded-full ${teamColors[team.id] || 'bg-purple-100 text-purple-800'} player-team-badge`;
                                    }
                                });
                            });
                        }
                    })
                    .catch(error => console.error('Error al actualizar badges:', error));
            @endif
        }

        // Actualizar badges al cargar
        document.addEventListener('DOMContentLoaded', function() {
            updatePlayerTeamBadges();

            // Actualizar cada 5 segundos
            setInterval(updatePlayerTeamBadges, 5000);
        });
    </script>
    @endpush
</x-app-layout>

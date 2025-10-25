/**
 * TeamManager.js
 *
 * Gestiona los equipos en el lobby:
 * - Creación y configuración de equipos
 * - Drag & Drop para asignar jugadores (solo master)
 * - Auto-selección de equipos (jugadores pueden elegir)
 * - Actualización en tiempo real vía WebSocket
 * - Validación de equipos antes de iniciar partida
 *
 * IMPORTANTE: NO usa location.reload() - todo es dinámico con WebSockets
 */

export class TeamManager {
    constructor(roomCode, options = {}) {
        this.roomCode = roomCode;
        this.isMaster = options.isMaster || false;
        this.currentPlayerId = options.currentPlayerId || null;
        this.defaultTeamMode = options.defaultTeamMode || 'team_turns';

        // Estado
        this.allowSelfSelection = false;
        this.draggedPlayerId = null;
        this.teams = [];

        // Colores para los equipos
        this.teamColors = ['bg-red-100 border-red-300', 'bg-blue-100 border-blue-300', 'bg-green-100 border-green-300', 'bg-yellow-100 border-yellow-300'];
        this.teamBadgeColors = {
            'team_1': 'bg-red-100 text-red-800',
            'team_2': 'bg-blue-100 text-blue-800',
            'team_3': 'bg-green-100 text-green-800',
            'team_4': 'bg-yellow-100 text-yellow-800'
        };

        console.log('🏆 TeamManager initialized for room:', roomCode);
    }

    /**
     * Inicializa el gestor de equipos
     */
    initialize() {
        console.log('🏆 TeamManager: Initializing...');

        // Cargar equipos existentes
        this.loadExistingTeams();

        // Actualizar badges de equipos en lista de jugadores
        this.updatePlayerTeamBadges();

        // Configurar WebSocket para actualizaciones en tiempo real
        this.setupTeamsWebSocket();

        // Exponer métodos globalmente para que el HTML los pueda llamar
        window.changeTeamCount = this.changeTeamCount.bind(this);
        window.initializeTeams = this.initializeTeams.bind(this);
        window.joinTeam = this.joinTeam.bind(this);
        window.removeFromTeam = this.removeFromTeam.bind(this);
        window.balanceTeams = this.balanceTeams.bind(this);
        window.resetTeams = this.resetTeams.bind(this);
        window.toggleSelfSelection = this.toggleSelfSelection.bind(this);
        window.handleDragStart = this.handleDragStart.bind(this);
        window.handleDragEnd = this.handleDragEnd.bind(this);
    }

    /**
     * Cambia el número de equipos (incremento/decremento)
     */
    changeTeamCount(delta) {
        const input = document.getElementById('team-count');
        if (!input) return;

        let value = parseInt(input.value) + delta;
        if (value >= 2 && value <= 4) {
            input.value = value;
        }
    }

    /**
     * Crea los equipos vía API
     */
    async initializeTeams() {
        const input = document.getElementById('team-count');
        if (!input) return;

        const count = parseInt(input.value);

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/teams/enable`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    mode: this.defaultTeamMode,
                    num_teams: count
                })
            });

            const data = await response.json();

            if (data.success) {
                // Ocultar panel de creación y mostrar paneles activos
                const createPanel = document.getElementById('create-teams-panel');
                const teamsCreatedPanel = document.getElementById('teams-created-panel');
                const assignmentPanel = document.getElementById('assignment-mode-panel');

                if (createPanel) createPanel.classList.add('hidden');
                if (teamsCreatedPanel) teamsCreatedPanel.classList.remove('hidden');
                if (assignmentPanel) assignmentPanel.classList.remove('hidden');

                // Mostrar área de equipos
                const teamsArea = document.getElementById('teams-area');
                if (teamsArea) teamsArea.classList.remove('hidden');

                // Renderizar equipos (auto-selección deshabilitada por defecto)
                this.renderTeams(data.teams, false);
            } else {
                alert('Error al crear equipos: ' + (data.error || 'Error desconocido'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al crear equipos');
        }
    }

    /**
     * Renderiza los equipos en el DOM
     */
    renderTeams(teams, selfSelectionEnabled = false) {
        this.allowSelfSelection = selfSelectionEnabled;
        this.teams = teams;

        const container = document.getElementById('teams-container');
        if (!container) return;

        container.innerHTML = teams.map((team, index) => `
            <div class="border-2 rounded-lg p-4 ${this.teamColors[index % this.teamColors.length]}">
                <div class="flex items-center justify-between mb-3">
                    <h5 class="font-bold text-lg">${team.name}</h5>
                    <span class="text-sm font-medium px-2 py-1 bg-white rounded-full">${team.members.length} jugadores</span>
                </div>
                <div
                    id="team-${team.id}"
                    data-team-id="${team.id}"
                    class="space-y-2 min-h-[100px] ${this.isMaster ? 'drop-zone' : ''}"
                >
                    ${team.members.length === 0 ? '<p class="text-sm text-gray-500 text-center py-4">Sin jugadores</p>' : ''}
                    ${team.members.map(memberId => this.renderPlayerInTeam(memberId)).join('')}
                </div>
                ${this.allowSelfSelection && this.currentPlayerId && !team.members.includes(this.currentPlayerId) ? `
                    <button
                        onclick="joinTeam('${team.id}')"
                        class="w-full mt-3 px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg transition"
                    >
                        Unirme a este equipo
                    </button>
                ` : ''}
            </div>
        `).join('');

        // Agregar event listeners a las drop zones después de renderizar
        if (this.isMaster) {
            document.querySelectorAll('.drop-zone').forEach(dropZone => {
                dropZone.addEventListener('dragover', this.handleDragOver.bind(this));
                dropZone.addEventListener('dragenter', this.handleDragEnter.bind(this));
                dropZone.addEventListener('dragleave', this.handleDragLeave.bind(this));
                dropZone.addEventListener('drop', this.handleDrop.bind(this));
            });
        }
    }

    /**
     * Renderiza un jugador dentro de un equipo
     */
    renderPlayerInTeam(playerId) {
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

    // ========================================================================
    // DRAG & DROP para asignación manual (solo master)
    // ========================================================================

    handleDragStart(event) {
        this.draggedPlayerId = event.target.dataset.playerId;
        event.target.style.opacity = '0.4';
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/html', event.target.innerHTML);
    }

    handleDragEnd(event) {
        event.target.style.opacity = '1';
    }

    handleDragOver(event) {
        if (event.preventDefault) {
            event.preventDefault();
        }
        event.dataTransfer.dropEffect = 'move';
        return false;
    }

    handleDragEnter(event) {
        const dropZone = event.currentTarget;
        if (dropZone.classList.contains('drop-zone')) {
            dropZone.classList.add('border-4', 'border-purple-500', 'bg-purple-50');
        }
    }

    handleDragLeave(event) {
        const dropZone = event.currentTarget;
        if (dropZone.classList.contains('drop-zone')) {
            dropZone.classList.remove('border-4', 'border-purple-500', 'bg-purple-50');
        }
    }

    async handleDrop(event) {
        if (event.stopPropagation) {
            event.stopPropagation();
        }

        const dropZone = event.currentTarget;
        dropZone.classList.remove('border-4', 'border-purple-500', 'bg-purple-50');

        if (!this.draggedPlayerId) return false;

        const teamId = dropZone.dataset.teamId;

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/teams/assign`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    player_id: parseInt(this.draggedPlayerId),
                    team_id: teamId
                })
            });

            const data = await response.json();

            if (data.success) {
                console.log('Jugador asignado correctamente');
                // La actualización se hará automáticamente via WebSocket
            } else {
                alert('Error: ' + (data.error || 'No se pudo asignar el jugador'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al asignar jugador');
        }

        this.draggedPlayerId = null;
        return false;
    }

    // ========================================================================
    // OPERACIONES DE EQUIPOS
    // ========================================================================

    /**
     * Unirse a un equipo (para jugadores cuando está habilitada la auto-selección)
     */
    async joinTeam(teamId) {
        if (!this.currentPlayerId) {
            alert('Debes estar logueado para unirte a un equipo');
            return;
        }

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/teams/assign`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    player_id: this.currentPlayerId,
                    team_id: teamId
                })
            });

            const data = await response.json();

            if (data.success) {
                console.log('Unido al equipo exitosamente');
                // La actualización se hará automáticamente via WebSocket
            } else {
                alert('Error: ' + (data.error || 'No se pudo unir al equipo'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al unirse al equipo');
        }
    }

    /**
     * Remover un jugador de su equipo
     */
    async removeFromTeam(playerId) {
        if (!confirm('¿Remover este jugador del equipo?')) {
            return;
        }

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/teams/players/${playerId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            const data = await response.json();

            if (data.success) {
                console.log('Jugador removido del equipo');
                // La actualización se hará automáticamente via WebSocket
            } else {
                alert('Error: ' + (data.error || 'No se pudo remover el jugador'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al remover jugador');
        }
    }

    /**
     * Distribuir jugadores equitativamente entre equipos
     */
    async balanceTeams() {
        if (!confirm('¿Distribuir automáticamente todos los jugadores en los equipos de forma equitativa?')) {
            return;
        }

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/teams/balance`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            const data = await response.json();

            if (data.success) {
                // Actualizar vista de equipos
                this.loadExistingTeams();
                this.updatePlayerTeamBadges();
                alert('✓ Jugadores distribuidos en equipos');
            } else {
                alert('Error: ' + (data.error || 'No se pudo balancear los equipos'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al balancear equipos');
        }
    }

    /**
     * Reiniciar equipos (eliminar todos los equipos)
     */
    async resetTeams() {
        if (!confirm('¿Estás seguro de reiniciar los equipos? Todos los jugadores serán desasignados.')) {
            return;
        }

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/teams/disable`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            const data = await response.json();

            if (data.success) {
                // Mostrar panel de creación
                const createPanel = document.getElementById('create-teams-panel');
                const teamsCreatedPanel = document.getElementById('teams-created-panel');
                const assignmentPanel = document.getElementById('assignment-mode-panel');
                const teamsArea = document.getElementById('teams-area');

                if (createPanel) createPanel.classList.remove('hidden');
                if (teamsCreatedPanel) teamsCreatedPanel.classList.add('hidden');
                if (assignmentPanel) assignmentPanel.classList.add('hidden');
                if (teamsArea) teamsArea.classList.add('hidden');

                alert('✓ Equipos reiniciados');
            } else {
                alert('Error: ' + (data.error || 'No se pudo reiniciar'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al reiniciar equipos');
        }
    }

    /**
     * Activar/desactivar auto-selección de equipos
     */
    async toggleSelfSelection(enabled) {
        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/teams/self-selection`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    allow_self_selection: enabled
                })
            });

            const data = await response.json();

            if (data.success) {
                console.log('Auto-selección actualizada:', enabled);
                // La actualización se hará automáticamente via WebSocket
            } else {
                alert('Error al actualizar configuración');
            }
        } catch (error) {
            console.error('Error:', error);
        }
    }

    // ========================================================================
    // ACTUALIZACIÓN DE BADGES Y ESTADO
    // ========================================================================

    /**
     * Actualiza los badges de equipo en la lista de jugadores
     */
    async updatePlayerTeamBadges() {
        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/teams`);
            const data = await response.json();

            if (data.success && data.teams) {
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
                            badge.className = `text-xs font-medium px-2 py-0.5 rounded-full ${this.teamBadgeColors[team.id] || 'bg-purple-100 text-purple-800'} player-team-badge`;
                        }
                    });
                });
            }
        } catch (error) {
            console.error('Error al actualizar badges:', error);
        }
    }

    /**
     * Carga los equipos existentes desde el servidor
     */
    async loadExistingTeams() {
        console.log('🏆 Loading existing teams...');

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/teams`);
            const data = await response.json();

            console.log('🏆 Teams data received:', data);

            if (data.success && data.enabled && data.teams && data.teams.length > 0) {
                // Ocultar panel de creación y mostrar paneles activos (solo para master)
                const createPanel = document.getElementById('create-teams-panel');
                const teamsCreatedPanel = document.getElementById('teams-created-panel');
                const assignmentPanel = document.getElementById('assignment-mode-panel');

                if (createPanel) createPanel.classList.add('hidden');
                if (teamsCreatedPanel) teamsCreatedPanel.classList.remove('hidden');
                if (assignmentPanel) assignmentPanel.classList.remove('hidden');

                // Mostrar área de equipos
                const teamsArea = document.getElementById('teams-area');
                if (teamsArea) teamsArea.classList.remove('hidden');

                console.log('🏆 Rendering teams:', data.teams);
                this.renderTeams(data.teams, data.allow_self_selection || false);

                // Sincronizar el estado del radio button
                if (data.allow_self_selection) {
                    const selfRadio = document.querySelector('input[name="assignment_mode"][value="self"]');
                    if (selfRadio) selfRadio.checked = true;
                } else {
                    const manualRadio = document.querySelector('input[name="assignment_mode"][value="manual"]');
                    if (manualRadio) manualRadio.checked = true;
                }
            }
        } catch (error) {
            console.error('Error loading teams:', error);
        }
    }

    /**
     * Valida que todos los equipos tengan al menos un jugador
     * @returns {Object} { valid: boolean, message: string }
     */
    validateTeamsForStart() {
        if (this.teams.length === 0) {
            return { valid: true, message: '' }; // No hay equipos configurados, OK
        }

        const teamsWithPlayers = this.teams.filter(team => team.members && team.members.length > 0);

        if (teamsWithPlayers.length < 2) {
            return {
                valid: false,
                message: 'Se necesitan al menos 2 equipos con jugadores para iniciar'
            };
        }

        const emptyTeams = this.teams.filter(team => !team.members || team.members.length === 0);
        if (emptyTeams.length > 0) {
            return {
                valid: false,
                message: `Hay equipos sin jugadores: ${emptyTeams.map(t => t.name).join(', ')}`
            };
        }

        return { valid: true, message: '' };
    }

    // ========================================================================
    // WEBSOCKET - Actualizaciones en tiempo real
    // ========================================================================

    /**
     * Configura los listeners de WebSocket para eventos de equipos
     */
    setupTeamsWebSocket() {
        if (typeof Echo === 'undefined') {
            console.warn('🏆 Echo no disponible, no se pueden configurar WebSocket listeners');
            return;
        }

        console.log('🏆 Setting up WebSocket listeners for teams...');

        Echo.channel(`lobby.${this.roomCode}`)
            .listen('.teams.balanced', (e) => {
                console.log('🏆 Equipos balanceados:', e);
                this.loadExistingTeams();
                this.updatePlayerTeamBadges();
            })
            .listen('.teams.config-updated', (e) => {
                console.log('🏆 Configuración de equipos actualizada:', e);
                this.loadExistingTeams();
            })
            .listen('.player.moved', (e) => {
                console.log('🏆 Jugador movido a equipo:', e);
                this.loadExistingTeams();
                this.updatePlayerTeamBadges();
            })
            .listen('.player.removed', (e) => {
                console.log('🏆 Jugador removido de equipo:', e);
                this.loadExistingTeams();
                this.updatePlayerTeamBadges();
            });
    }
}

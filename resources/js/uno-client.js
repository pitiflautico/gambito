/**
 * UNO Game Client
 *
 * Maneja toda la lógica del frontend para el juego UNO:
 * - Renderizado de cartas
 * - Eventos de WebSocket
 * - Acciones del jugador
 * - Actualización de UI en tiempo real
 */
export class UnoGameClient {
    constructor(config) {
        this.roomCode = config.roomCode;
        this.matchId = config.matchId;
        this.playerId = config.playerId;
        this.gameSlug = config.gameSlug;
        this.eventConfig = config.eventConfig;
        this.csrfToken = config.csrfToken;

        this.gameState = null;
        this.eventManager = null;
        this.pendingCardToPlay = null;
    }

    /**
     * Inicializar el cliente
     */
    async initialize() {
        console.log('[UNO] Initializing client...');

        // Cargar estado inicial
        await this.loadInitialState();

        // Configurar EventManager
        this.setupEventManager();

        // Configurar event listeners
        this.setupEventListeners();

        // Mostrar estado del juego
        this.showGameState();
        this.renderGameState();
    }

    /**
     * Cargar el estado inicial del juego
     */
    async loadInitialState() {
        try {
            const response = await fetch(`/api/uno/${this.roomCode}/state`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken
                }
            });

            const data = await response.json();

            if (data.success) {
                this.gameState = data.state;
                console.log('[UNO] Initial state loaded:', this.gameState);
            }
        } catch (error) {
            console.error('[UNO] Error loading initial state:', error);
        }
    }

    /**
     * Configurar EventManager para WebSockets
     */
    setupEventManager() {
        if (!window.EventManager) {
            console.error('[UNO] EventManager not found');
            return;
        }

        this.eventManager = new window.EventManager({
            roomCode: this.roomCode,
            gameSlug: this.gameSlug,
            eventConfig: this.eventConfig,
            handlers: {
                handleCardPlayed: (e) => this.onCardPlayed(e),
                handleCardDrawn: (e) => this.onCardDrawn(e),
                handleTurnChanged: (e) => this.onTurnChanged(e),
                handleUnoCalled: (e) => this.onUnoCalled(e),
                handlePlayerWon: (e) => this.onPlayerWon(e),
                handleRoundEnded: (e) => this.onRoundEnded(e),
                handleGameFinished: (e) => this.onGameFinished(e),
            },
            autoConnect: true
        });

        console.log('[UNO] EventManager configured');
    }

    /**
     * Configurar event listeners del DOM
     */
    setupEventListeners() {
        // Botón de robar carta
        document.getElementById('draw-btn')?.addEventListener('click', () => {
            this.drawCard();
        });

        // Botón UNO
        document.getElementById('uno-btn')?.addEventListener('click', () => {
            this.declareUno();
        });

        // Selector de color
        document.querySelectorAll('.color-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const color = e.currentTarget.dataset.color;
                this.onColorSelected(color);
            });
        });
    }

    /**
     * Mostrar el estado del juego (ocultar loading)
     */
    showGameState() {
        document.getElementById('loading-state')?.classList.add('hidden');
        document.getElementById('game-state')?.classList.remove('hidden');
    }

    /**
     * Renderizar el estado completo del juego
     */
    renderGameState() {
        if (!this.gameState) return;

        this.renderCurrentCard();
        this.renderPlayerHand();
        this.renderOtherPlayers();
        this.renderTurnIndicator();
        this.renderDirection();
        this.renderDeckCount();
        this.renderPendingDraw();
        this.updateUnoButton();
    }

    /**
     * Renderizar la carta actual en el centro
     */
    renderCurrentCard() {
        const currentCard = this.gameState.current_card;
        const currentColor = this.gameState.current_color;
        const cardElement = document.getElementById('current-card');

        if (!cardElement || !currentCard) return;

        const cardHtml = this.createCardHtml(currentCard, false);
        cardElement.innerHTML = cardHtml;

        // Añadir borde del color actual si es Wild
        if (currentCard.type === 'wild' && currentColor) {
            cardElement.style.borderColor = this.getColorHex(currentColor);
        }
    }

    /**
     * Renderizar la mano del jugador
     */
    renderPlayerHand() {
        const hand = this.gameState.player_hands?.[this.playerId] || [];
        const handElement = document.getElementById('player-hand');
        const handCountElement = document.getElementById('hand-count');

        if (!handElement) return;

        // Actualizar contador
        if (handCountElement) {
            handCountElement.textContent = hand.length;
        }

        // Limpiar y renderizar cartas
        handElement.innerHTML = '';

        hand.forEach((card, index) => {
            const cardDiv = document.createElement('div');
            cardDiv.className = 'card';
            cardDiv.innerHTML = this.createCardHtml(card, true);

            // Verificar si la carta se puede jugar
            const canPlay = this.canPlayCard(card);
            if (!canPlay) {
                cardDiv.classList.add('disabled');
            }

            // Event listener para jugar la carta
            cardDiv.addEventListener('click', () => {
                if (canPlay && this.isMyTurn()) {
                    this.playCard(index, card);
                }
            });

            handElement.appendChild(cardDiv);
        });
    }

    /**
     * Renderizar otros jugadores
     */
    renderOtherPlayers() {
        const playersList = document.getElementById('players-list');
        if (!playersList) return;

        playersList.innerHTML = '';

        const allPlayers = this.gameState.all_players || [];
        const currentPlayerId = this.gameState.current_player_id;

        allPlayers.forEach(player => {
            if (player.id === this.playerId) return; // Skip el jugador actual

            const playerDiv = document.createElement('div');
            playerDiv.className = 'player-card bg-gray-100';

            if (player.id === currentPlayerId) {
                playerDiv.classList.add('active');
            }

            const cardCount = this.gameState.player_hands?.[player.id]?.length || 0;
            const hasUno = cardCount === 1;

            playerDiv.innerHTML = `
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center text-white font-bold">
                        ${player.name.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <p class="font-semibold text-sm">${player.name}</p>
                        <p class="text-xs text-gray-600">${cardCount} carta${cardCount !== 1 ? 's' : ''}</p>
                        ${hasUno ? '<p class="text-xs text-red-600 font-bold">¡UNO!</p>' : ''}
                    </div>
                </div>
            `;

            playersList.appendChild(playerDiv);
        });
    }

    /**
     * Renderizar indicador de turno
     */
    renderTurnIndicator() {
        const currentPlayerNameElement = document.getElementById('current-player-name');
        const myTurnIndicator = document.getElementById('my-turn-indicator');

        if (!currentPlayerNameElement) return;

        const currentPlayerId = this.gameState.current_player_id;
        const currentPlayer = this.gameState.all_players?.find(p => p.id === currentPlayerId);

        if (currentPlayer) {
            currentPlayerNameElement.textContent = currentPlayer.name;
        }

        // Mostrar/ocultar indicador "ES TU TURNO"
        if (this.isMyTurn()) {
            myTurnIndicator?.classList.remove('hidden');
        } else {
            myTurnIndicator?.classList.add('hidden');
        }
    }

    /**
     * Renderizar dirección del juego
     */
    renderDirection() {
        const directionElement = document.getElementById('direction-indicator');
        if (!directionElement) return;

        const direction = this.gameState.direction || 1;
        directionElement.textContent = direction === 1 ? '↻' : '↺';
    }

    /**
     * Renderizar contador de cartas en el mazo
     */
    renderDeckCount() {
        const deckCountElement = document.getElementById('deck-count');
        if (!deckCountElement) return;

        const deckSize = this.gameState.deck?.length || 0;
        deckCountElement.textContent = `${deckSize} cartas`;
    }

    /**
     * Renderizar indicador de pending draw
     */
    renderPendingDraw() {
        const pendingDraw = this.gameState.pending_draw || 0;
        const indicator = document.getElementById('pending-draw-indicator');
        const count = document.getElementById('pending-draw-count');

        if (!indicator) return;

        if (pendingDraw > 0) {
            indicator.classList.remove('hidden');
            if (count) count.textContent = pendingDraw;
        } else {
            indicator.classList.add('hidden');
        }
    }

    /**
     * Actualizar botón UNO
     */
    updateUnoButton() {
        const unoBtn = document.getElementById('uno-btn');
        if (!unoBtn) return;

        const hand = this.gameState.player_hands?.[this.playerId] || [];
        const unoDeclared = this.gameState.uno_declared?.[this.playerId] || false;

        if (hand.length === 1 && !unoDeclared) {
            unoBtn.classList.remove('hidden');
        } else {
            unoBtn.classList.add('hidden');
        }
    }

    /**
     * Crear HTML para una carta
     */
    createCardHtml(card, showFull = true) {
        if (!card) return '';

        const colorClass = card.color ? `card-${card.color}` : 'card-wild';
        let symbol = '';
        let valueText = '';

        if (card.type === 'number') {
            symbol = card.value;
            valueText = card.value;
        } else if (card.type === 'special') {
            switch (card.value) {
                case 'skip':
                    symbol = '⊘';
                    valueText = 'Skip';
                    break;
                case 'reverse':
                    symbol = '⇄';
                    valueText = 'Reverse';
                    break;
                case 'draw2':
                    symbol = '+2';
                    valueText = '+2';
                    break;
            }
        } else if (card.type === 'wild') {
            symbol = card.value === 'wild_draw4' ? '+4' : 'W';
            valueText = card.value === 'wild_draw4' ? 'Wild +4' : 'Wild';
        }

        if (showFull) {
            return `
                <div class="${colorClass} w-full h-full rounded-lg p-2 flex flex-col justify-between">
                    <span class="text-xs">${symbol}</span>
                    <span class="card-value">${symbol}</span>
                    <span class="text-xs">${symbol}</span>
                </div>
            `;
        } else {
            return `<span class="text-6xl">${symbol}</span>`;
        }
    }

    /**
     * Verificar si una carta se puede jugar
     */
    canPlayCard(card) {
        const currentCard = this.gameState.current_card;
        const currentColor = this.gameState.current_color;
        const pendingDraw = this.gameState.pending_draw || 0;
        const allowStacking = this.gameState._config?.allow_stacking;

        // Si hay pending_draw
        if (pendingDraw > 0) {
            if (allowStacking) {
                return card.value === 'draw2' || card.value === 'wild_draw4';
            } else {
                return false;
            }
        }

        // Wild siempre se puede jugar
        if (card.type === 'wild') {
            return true;
        }

        // Mismo color o mismo valor
        if (card.color === currentColor) {
            return true;
        }

        if (card.value === currentCard.value) {
            return true;
        }

        return false;
    }

    /**
     * Verificar si es el turno del jugador actual
     */
    isMyTurn() {
        return this.gameState.current_player_id === this.playerId;
    }

    /**
     * Jugar una carta
     */
    async playCard(cardIndex, card) {
        // Si es Wild, mostrar selector de color
        if (card.type === 'wild') {
            this.pendingCardToPlay = cardIndex;
            this.showColorSelector();
            return;
        }

        // Jugar la carta directamente
        await this.sendPlayCardAction(cardIndex, null);
    }

    /**
     * Mostrar selector de color
     */
    showColorSelector() {
        document.getElementById('color-selector-modal')?.classList.remove('hidden');
    }

    /**
     * Ocultar selector de color
     */
    hideColorSelector() {
        document.getElementById('color-selector-modal')?.classList.add('hidden');
    }

    /**
     * Handler cuando se selecciona un color
     */
    async onColorSelected(color) {
        this.hideColorSelector();

        if (this.pendingCardToPlay !== null) {
            await this.sendPlayCardAction(this.pendingCardToPlay, color);
            this.pendingCardToPlay = null;
        }
    }

    /**
     * Enviar acción de jugar carta al servidor
     */
    async sendPlayCardAction(cardIndex, chosenColor = null) {
        try {
            const response = await fetch(`/api/uno/${this.roomCode}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken
                },
                body: JSON.stringify({
                    action: 'play_card',
                    card_index: cardIndex,
                    chosen_color: chosenColor
                })
            });

            const result = await response.json();

            if (!result.success) {
                alert(result.error || 'No puedes jugar esa carta');
            }
        } catch (error) {
            console.error('[UNO] Error playing card:', error);
            alert('Error al jugar la carta');
        }
    }

    /**
     * Robar carta
     */
    async drawCard() {
        if (!this.isMyTurn()) {
            alert('No es tu turno');
            return;
        }

        try {
            const response = await fetch(`/api/uno/${this.roomCode}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken
                },
                body: JSON.stringify({
                    action: 'draw_card'
                })
            });

            const result = await response.json();

            if (!result.success) {
                alert(result.error || 'Error al robar carta');
            }
        } catch (error) {
            console.error('[UNO] Error drawing card:', error);
            alert('Error al robar carta');
        }
    }

    /**
     * Declarar UNO
     */
    async declareUno() {
        try {
            const response = await fetch(`/api/uno/${this.roomCode}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken
                },
                body: JSON.stringify({
                    action: 'declare_uno'
                })
            });

            const result = await response.json();

            if (!result.success) {
                alert(result.error || 'Error al declarar UNO');
            }
        } catch (error) {
            console.error('[UNO] Error declaring UNO:', error);
            alert('Error al declarar UNO');
        }
    }

    /**
     * Obtener color hex
     */
    getColorHex(color) {
        const colors = {
            'red': '#EF4444',
            'blue': '#3B82F6',
            'green': '#10B981',
            'yellow': '#F59E0B'
        };
        return colors[color] || '#000000';
    }

    // ============================================
    // Event Handlers (WebSocket)
    // ============================================

    onCardPlayed(event) {
        console.log('[UNO] Card played:', event);
        this.gameState = event.game_state;
        this.renderGameState();

        // Mostrar notificación
        if (event.player_id !== this.playerId) {
            this.showNotification(`${event.player_name} jugó una carta`);
        }
    }

    onCardDrawn(event) {
        console.log('[UNO] Card drawn:', event);
        this.gameState = event.game_state;
        this.renderGameState();

        if (event.player_id !== this.playerId) {
            this.showNotification(`${event.player_name} robó ${event.card_count} carta(s)`);
        }
    }

    onTurnChanged(event) {
        console.log('[UNO] Turn changed:', event);
        this.gameState = event.game_state;
        this.renderGameState();
    }

    onUnoCalled(event) {
        console.log('[UNO] UNO called:', event);
        this.gameState = event.game_state;
        this.renderGameState();
        this.showNotification(`¡${event.player_name} dijo UNO!`, 'success');
    }

    onPlayerWon(event) {
        console.log('[UNO] Player won:', event);
        this.gameState = event.game_state;
        this.showWinScreen(event);
    }

    onRoundEnded(event) {
        console.log('[UNO] Round ended:', event);
        this.gameState = event.game_state;
    }

    onGameFinished(event) {
        console.log('[UNO] Game finished:', event);
        this.showFinalResults(event);
    }

    /**
     * Mostrar pantalla de victoria
     */
    showWinScreen(event) {
        const winnerInfo = document.getElementById('winner-info');
        if (winnerInfo) {
            winnerInfo.innerHTML = `
                <p class="text-2xl font-bold text-green-600">
                    ${event.player_name} ganó la ronda!
                </p>
                <p class="text-lg text-gray-600 mt-2">
                    +${event.points_earned} puntos
                </p>
            `;
        }

        document.getElementById('game-state')?.classList.add('hidden');
        document.getElementById('finished-state')?.classList.remove('hidden');
    }

    /**
     * Mostrar resultados finales
     */
    showFinalResults(event) {
        // Similar a showWinScreen pero con tabla completa de puntuaciones
        this.showWinScreen(event);
    }

    /**
     * Mostrar notificación
     */
    showNotification(message, type = 'info') {
        // Implementar sistema de notificaciones toast
        console.log(`[NOTIFICATION ${type}]:`, message);
    }
}

export default UnoGameClient;

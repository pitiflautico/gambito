// BaseGameClient ya está disponible globalmente a través de resources/js/app.js
const { BaseGameClient } = window;

export class MockupGameClient extends BaseGameClient {
    constructor(config) {
        super(config);

        console.log('[MockupClient] Constructor called with config:', config);

        // Setup EventManager con handlers personalizados
        this.setupEventManager();
    }

    /**
     * Override: Configurar EventManager con handlers específicos de Mockup
     */
    setupEventManager() {
        console.log('[MockupClient] Setting up EventManager...');

        // Registrar handlers personalizados de Mockup
        const customHandlers = {
            handleGameStarted: (event) => {
                console.log('🎮 ========================================');
                console.log('🎮 PARTIDA INICIADA');
                console.log('🎮 ========================================');
                console.log('Data:', event);
                this.handleGameStarted(event);
            },
            handlePhaseChanged: (event) => {
                console.log('🔄 ========================================');
                console.log('🔄 CAMBIO DE FASE');
                console.log('🔄 ========================================');
                console.log('Fase:', event.phase);
                console.log('Duración:', event.duration, 'segundos');
                console.log('Ronda:', event.round);
                console.log('Data completa:', event);
                this.handlePhaseChanged(event);
            },
            handleRoundStarted: (event) => {
                console.log('🔵 ========================================');
                console.log('🔵 RONDA INICIADA');
                console.log('🔵 ========================================');
                console.log('Ronda:', event.round);
                console.log('Total rondas:', event.total_rounds);
                console.log('Data completa:', event);
                this.handleRoundStarted(event);
            },
            handleRoundEnded: (event) => {
                console.log('🔴 ========================================');
                console.log('🔴 RONDA TERMINADA');
                console.log('🔴 ========================================');
                console.log('Ronda:', event.round);
                console.log('Resultados:', event.results);
                console.log('Scores:', event.scores);
                console.log('Data completa:', event);
                this.handleRoundEnded(event);
            },
            handleGameFinished: (event) => {
                console.log('🏁 ========================================');
                console.log('🏁 PARTIDA TERMINADA');
                console.log('🏁 ========================================');
                console.log('Ganador:', event.winner);
                console.log('Ranking:', event.ranking);
                console.log('Data completa:', event);
                this.handleGameFinished(event);
            },
            handlePhase1Ended: (event) => {
                console.log('⭐ ========================================');
                console.log('⭐ FASE 1 COMPLETADA (Custom Event)');
                console.log('⭐ ========================================');
                console.log('Data:', event);
                // Este es solo para demostrar eventos custom
            }
        };

        // Llamar al setupEventManager del padre con los handlers custom
        super.setupEventManager(customHandlers);

        console.log('[MockupClient] EventManager configured successfully');
    }

    /**
     * Override: Handler de cambio de fase (para actualizar UI específica)
     */
    handlePhaseChanged(event) {
        super.handlePhaseChanged(event);

        // Actualizar display de fase en el DOM
        const phaseEl = document.getElementById('current-phase');
        if (phaseEl) {
            phaseEl.textContent = event.phase || 'unknown';
        }

        // Actualizar descripción de fase
        const descEl = document.getElementById('phase-description');
        if (descEl) {
            descEl.textContent = `Fase ${event.phase} en progreso...`;
        }
    }

    /**
     * Override: Handler de ronda iniciada (para actualizar UI específica)
     */
    handleRoundStarted(event) {
        super.handleRoundStarted(event);

        // Actualizar display de ronda en el DOM
        const roundEl = document.getElementById('current-round');
        if (roundEl) {
            roundEl.textContent = event.round || 1;
        }
    }
}

// Hacer disponible globalmente
window.MockupGameClient = MockupGameClient;

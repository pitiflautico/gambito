// BaseGameClient ya está disponible globalmente a través de resources/js/app.js
const { BaseGameClient } = window;

export class MockupGameClient extends BaseGameClient {
    constructor(config) {
        super(config);
        this.customHandlers = null; // Guardar referencia a los handlers
        this.setupEventManager();
    }

    /**
     * Override: Configurar EventManager con handlers específicos de Mockup
     */
    setupEventManager() {
        // Registrar handlers personalizados de Mockup
        this.customHandlers = {
            handleDomLoaded: (event) => {
                // Llamar al handler del padre primero
                super.handleDomLoaded(event);
            },
            handleGameStarted: (event) => {
                console.log('🎮 [Mockup] JUEGO INICIADO', event);
            },
            handlePhase1Started: (event) => {
                console.log('🎯 [Mockup] FASE 1 INICIADA - Timer de 5 segundos comenzando', event);
            },
            handlePhase1Ended: (event) => {
                console.log('🏁 [Mockup] FASE 1 FINALIZADA - Timer expirado correctamente', event);
            }
        };

        // Llamar al setupEventManager del padre con los handlers custom
        super.setupEventManager(this.customHandlers);
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

/**
 * TriviaGame - Extiende BaseGameClient
 *
 * VERSIÓN MÍNIMA PARA PROBAR EVENTOS
 */
import { BaseGameClient } from './core/BaseGameClient.js';

class TriviaGame extends BaseGameClient {
    constructor(config) {
        // Llamar al constructor del padre
        super(config);

        // Configurar EventManager con handlers custom de Trivia
        this.setupEventManager({
            handleRoundStarted: (event) => this.handleRoundStartedTrivia(event),
            handleRoundEnded: (event) => this.handleRoundEndedTrivia(event),
            handlePlayerAction: (event) => this.handlePlayerActionTrivia(event),
            handlePhaseChanged: (event) => this.handlePhaseChangedTrivia(event),
            handleTurnChanged: (event) => this.handleTurnChangedTrivia(event),
            handleGameFinished: (event) => this.handleGameFinishedTrivia(event),
        });

    }

    // ========================================================================
    // HANDLERS ESPECÍFICOS DE TRIVIA
    // ========================================================================

    /**
     * Handler de Trivia para RoundStarted
     */
    handleRoundStartedTrivia(event) {

        // Llamar al handler base
        super.handleRoundStarted(event);

        // TODO: Renderizar pregunta en UI
    }

    /**
     * Handler de Trivia para RoundEnded
     */
    handleRoundEndedTrivia(event) {

        // Llamar al handler base
        super.handleRoundEnded(event);

        // TODO: Mostrar resultados en UI
    }

    /**
     * Handler de Trivia para PlayerAction
     */
    handlePlayerActionTrivia(event) {

        // Llamar al handler base
        super.handlePlayerAction(event);

        // TODO: Mostrar indicador de "jugador X está respondiendo..."
    }

    /**
     * Handler de Trivia para PhaseChanged
     */
    handlePhaseChangedTrivia(event) {

        // TODO: Cambiar UI según la fase (waiting → playing → results → finished)
    }

    /**
     * Handler de Trivia para TurnChanged
     */
    handleTurnChangedTrivia(event) {

        // TODO: Actualizar indicador de turno (si aplica en Trivia)
    }

    /**
     * Handler de Trivia para GameFinished
     */
    handleGameFinishedTrivia(event) {
        if (event.statistics && event.statistics.winner) {
        }

        // TODO: Mostrar pantalla de resultados finales y ganador
    }
}

// Exportar a window para acceso global
window.TriviaGame = TriviaGame;

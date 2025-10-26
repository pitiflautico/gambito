<?php

namespace App\Services\Modules\RoundSystem;

use App\Services\Modules\TurnSystem\TurnManager;

/**
 * Servicio genérico para gestionar rondas en juegos.
 *
 * RESPONSABILIDADES:
 * - Conteo de rondas (actual, total)
 * - Timing entre rondas (emitir eventos, programar backups)
 * - Detección de fin de juego
 * - Contiene un TurnManager para gestionar turnos dentro de la ronda
 *
 * NO ES RESPONSABLE DE:
 * - Estado de jugadores (eliminaciones, bloqueos, etc.) → PlayerStateManager
 * - Decisiones de negocio (cuándo termina ronda) → GameEngine + Strategies
 * - Equipos → TeamsManager (módulo separado)
 *
 * Ejemplos de uso:
 * - Trivia: N rondas, con timing de 3s entre rondas
 * - Pictionary: 5 rondas, timing personalizado
 */
class RoundManager
{
    /**
     * Ronda actual (1-based).
     */
    protected int $currentRound;

    /**
     * Total de rondas del juego (0 = infinitas).
     */
    protected int $totalRounds;

    /**
     * TurnManager interno para gestionar turnos (opcional).
     */
    protected ?TurnManager $turnManager;

    /**
     * Si se acaba de completar una ronda.
     */
    protected bool $roundJustCompleted = false;

    /**
     * Constructor.
     *
     * @param TurnManager|null $turnManager Gestor de turnos (opcional)
     * @param int $totalRounds Total de rondas (0 = infinitas)
     * @param int $currentRound Ronda inicial
     */
    public function __construct(
        ?TurnManager $turnManager = null,
        int $totalRounds = 0,
        int $currentRound = 1
    ) {
        $this->turnManager = $turnManager;
        $this->totalRounds = $totalRounds;
        $this->currentRound = $currentRound;
    }

    /**
     * Avanzar al siguiente turno.
     *
     * Delega al TurnManager y detecta cuando se completa una ronda.
     *
     * @return array Info del turno actual
     */
    public function nextTurn(): array
    {
        if (!$this->turnManager) {
            throw new \RuntimeException('TurnManager is not initialized. This game does not use turn system.');
        }

        $this->roundJustCompleted = false;

        // Delegar al TurnManager
        $turnInfo = $this->turnManager->nextTurn();

        // Detectar si completó un ciclo (= completó una ronda)
        if ($this->turnManager->isCycleComplete()) {
            $this->currentRound++;
            $this->roundJustCompleted = true;
        }

        return $turnInfo;
    }

    /**
     * Avanzar al siguiente turno Y avanzar la ronda (round-per-turn mode).
     *
     * En este modo, cada turno es una ronda completa.
     * Usado en juegos como Pictionary donde cada dibujante = 1 ronda.
     *
     * @return array Info del turno actual
     */
    public function nextTurnWithRoundAdvance(): array
    {
        $this->roundJustCompleted = false;

        // Delegar al TurnManager
        $turnInfo = $this->turnManager->nextTurn();

        // En round-per-turn mode, SIEMPRE avanzamos la ronda
        $this->currentRound++;
        $this->roundJustCompleted = true;

        return $turnInfo;
    }

    /**
     * Verificar si se acaba de completar una ronda.
     *
     * @return bool True si el último nextTurn() completó una ronda
     */
    public function isNewRound(): bool
    {
        return $this->roundJustCompleted;
    }

    /**
     * Obtener la ronda actual.
     *
     * @return int Ronda actual (1-based)
     */
    public function getCurrentRound(): int
    {
        return $this->currentRound;
    }

    /**
     * Obtener total de rondas.
     *
     * @return int Total de rondas (0 = infinitas)
     */
    public function getTotalRounds(): int
    {
        return $this->totalRounds;
    }

    /**
     * Verificar si el juego ha terminado.
     *
     * El juego termina cuando:
     * 1. Se alcanzó el total de rondas (si totalRounds > 0)
     * 2. O cuando cada juego decide (ej. Battle Royale: solo 1 jugador activo)
     *
     * Nota: Esta función solo verifica rondas. El game engine puede
     * agregar lógica adicional (ej. verificar jugadores activos).
     *
     * @return bool True si se completaron todas las rondas
     */
    public function isGameComplete(): bool
    {
        if ($this->totalRounds === 0) {
            return false; // Juego infinito
        }

        // El juego está completo cuando terminamos la última ronda
        return $this->currentRound >= $this->totalRounds;
    }

    // ========================================================================
    // ACCESO AL TURNMANAGER
    // ========================================================================

    /**
     * Obtener el TurnManager interno.
     *
     * @return TurnManager
     */
    public function getTurnManager(): ?TurnManager
    {
        return $this->turnManager;
    }

    /**
     * Obtener el jugador del turno actual.
     *
     * @return mixed ID del jugador en turno
     */
    public function getCurrentPlayer(): mixed
    {
        return $this->turnManager->getCurrentPlayer();
    }

    /**
     * Obtener el orden de turnos.
     *
     * @return array<int>
     */
    public function getTurnOrder(): array
    {
        return $this->turnManager->getTurnOrder();
    }

    /**
     * Verificar si es el turno de un jugador específico.
     *
     * @param int $playerId ID del jugador
     * @return bool
     */
    public function isPlayerTurn(int $playerId): bool
    {
        return $this->turnManager->isPlayerTurn($playerId);
    }

    /**
     * Pausar los turnos.
     *
     * @return void
     */
    public function pause(): void
    {
        $this->turnManager->pause();
    }

    /**
     * Reanudar los turnos.
     *
     * @return void
     */
    public function resume(): void
    {
        $this->turnManager->resume();
    }

    /**
     * Verificar si está pausado.
     *
     * @return bool
     */
    public function isPaused(): bool
    {
        return $this->turnManager->isPaused();
    }

    /**
     * Obtener índice del turno actual.
     *
     * @return int
     */
    public function getCurrentTurnIndex(): int
    {
        return $this->turnManager->getCurrentTurnIndex();
    }

    /**
     * Obtener cantidad de jugadores.
     *
     * @return int
     */
    public function getPlayerCount(): int
    {
        return $this->turnManager->getPlayerCount();
    }

    // ========================================================================
    // SERIALIZACIÓN
    // ========================================================================

    /**
     * Serializar a array para guardar en game_state.
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'round_system' => [
                'current_round' => $this->currentRound,
                'total_rounds' => $this->totalRounds,
            ],
        ];

        // Solo incluir turn_system si TurnManager está inicializado
        if ($this->turnManager) {
            $data['turn_system'] = $this->turnManager->toArray();
        }

        return $data;
    }

    /**
     * Restaurar desde array serializado.
     *
     * @param array $data Estado serializado
     * @return self Nueva instancia restaurada
     */
    public static function fromArray(array $data): self
    {
        // Soporte para ambos formatos: nuevo (round_system) y legacy (claves directas)
        $roundData = $data['round_system'] ?? $data;

        // Restaurar TurnManager solo si existe en el state
        $turnManager = null;
        if (isset($data['turn_system'])) {
            $turnManager = TurnManager::fromArray($data['turn_system']);

            // Si existe TimerService en el state, conectarlo automáticamente al TurnManager
            if (isset($data['timer_system'])) {
                $timerService = \App\Services\Modules\TimerSystem\TimerService::fromArray($data);
                $turnManager->setTimerService($timerService);
            }
        }

        $instance = new self(
            turnManager: $turnManager,
            totalRounds: $roundData['total_rounds'] ?? 0,
            currentRound: $roundData['current_round'] ?? 1
        );

        return $instance;
    }

    /**
     * Completar ronda actual.
     *
     * Responsabilidades de RoundManager:
     * - Emitir RoundEndedEvent con timing metadata
     * - Avanzar turno/ronda
     * - Programar backup automático si está configurado
     *
     * @param \App\Models\GameMatch $match
     * @param array $results Resultados de la ronda
     * @param array $scores Puntuaciones actuales
     * @return void
     */
    public function completeRound(\App\Models\GameMatch $match, array $results = [], array $scores = []): void
    {
        \Log::info('[RoundManager] completeRound() STARTED', [
            'match_id' => $match->id,
            'current_round' => $this->currentRound
        ]);
        
        try {
            // 1. Leer configuración de timing desde game_state
            $gameConfig = $match->game_state['_config'] ?? [];
            $timingConfig = $gameConfig['timing']['round_ended'] ?? null;
            $roundPerTurn = $gameConfig['modules']['turn_system']['round_per_turn'] ?? false;

            \Log::info('[RoundManager] Timing config', [
                'timing_config' => $timingConfig,
                'round_per_turn' => $roundPerTurn
            ]);

            // 2. Emitir evento RoundEndedEvent con timing metadata
            \Log::info('[RoundManager] About to emit RoundEndedEvent');
            event(new \App\Events\Game\RoundEndedEvent(
                match: $match,
                roundNumber: $this->currentRound,
                results: $results,
                scores: $scores,
                timing: $timingConfig
            ));
            \Log::info('[RoundManager] RoundEndedEvent emitted');

            // 3. NO avanzar la ronda aquí - eso lo hará handleNewRound() cuando se llame
            // El frontend esperará el countdown y luego llamará a /next-round
            // que ejecutará handleNewRound() que avanzará la ronda y emitirá RoundStartedEvent
            
            \Log::info('[RoundManager] Round NOT advanced yet - waiting for countdown/frontend to call next-round', [
                'current_round' => $this->currentRound
            ]);

            // 4. BACKUP DESHABILITADO TEMPORALMENTE
            // El backup automático requiere un queue asíncrono (redis/database)
            // Con queue=sync, el Job se ejecuta inmediatamente ignorando el delay
            // Por ahora, solo el frontend maneja el countdown y llama a /next-round
            // TODO: Configurar queue asíncrono y re-habilitar backup
            
            \Log::info('[RoundManager] Frontend countdown will trigger next round', [
                'timing_config' => $timingConfig
            ]);
            
            \Log::info('[RoundManager] completeRound() FINISHED');
        } catch (\Exception $e) {
            \Log::error('[RoundManager] ERROR in completeRound()', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Programar el avance automático a la siguiente ronda (BACKUP).
     *
     * Este job actúa como backup del frontend.
     * Usa el sistema de locks para evitar duplicados.
     *
     * @param int $matchId ID del match a avanzar
     * @param int $delaySeconds Segundos de espera antes de ejecutar
     * @return void
     */
    public function scheduleNextRound(int $matchId, int $delaySeconds = 5): void
    {
        \App\Jobs\StartNextRoundJob::dispatch($matchId)->delay(now()->addSeconds($delaySeconds));
    }


    /**
     * Resetear el RoundManager a su estado inicial.
     *
     * Usado por BaseGameEngine::resetModules() al iniciar/reiniciar el juego.
     * Vuelve a ronda 1.
     *
     * IMPORTANTE: También resetea el TurnManager si existe, lo que
     * automáticamente inicia el timer del primer turno.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->currentRound = 1;
        $this->roundJustCompleted = false;

        // Resetear TurnManager (esto inicia el timer automáticamente)
        if ($this->turnManager) {
            $this->turnManager->reset();
        }
    }

    /**
     * Avanzar a la siguiente ronda.
     *
     * Este método se llama desde BaseGameEngine::handleNewRound()
     * y se encarga de:
     * 1. Incrementar el contador de ronda
     * 2. Resetear turnos y timer (via TurnManager)
     *
     * IMPORTANTE: Este método NO llama a la lógica del juego.
     * Solo gestiona el estado de rondas/turnos/timer.
     *
     * @return void
     */
    public function advanceToNextRound(): void
    {
        // Incrementar ronda
        $this->currentRound++;

        // Resetear TurnManager (esto cancela timer anterior e inicia uno nuevo)
        if ($this->turnManager) {
            $this->turnManager->reset();
        }
    }
}

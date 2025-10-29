<?php

namespace App\Services\Modules\RoundSystem;

use App\Services\Modules\TurnSystem\TurnManager;
use App\Services\Modules\TurnSystem\PhaseManager;

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
     * Factory method: Crear RoundManager desde configuración del juego.
     *
     * Este método centraliza la inicialización completa del sistema de rondas:
     * - Lee la configuración de fases
     * - Crea PhaseManager con las fases configuradas
     * - Inicializa RoundManager con el PhaseManager
     *
     * @param array $config Configuración del juego (timing, modules, etc.)
     * @param array $playerIds IDs de jugadores para TurnManager
     * @param int $totalRounds Total de rondas
     * @return self RoundManager inicializado
     */
    public static function createFromConfig(array $config, array $playerIds, int $totalRounds): self
    {
        $modules = $config['modules'] ?? [];
        $timing = $config['timing'] ?? [];

        // Extraer configuración de turnos
        $turnConfig = $modules['turn_system'] ?? [];
        $mode = $turnConfig['mode'] ?? 'sequential';

        // Obtener configuración de fases
        $phases = self::extractPhasesFromConfig($config);

        // Crear PhaseManager (siempre, mínimo 1 fase)
        $phaseManager = new PhaseManager($phases);

        // Crear RoundManager con el PhaseManager
        return new self(
            turnManager: $phaseManager,
            totalRounds: $totalRounds,
            currentRound: 1
        );
    }

    /**
     * Extraer configuración de fases desde config del juego.
     *
     * Prioridad de extracción:
     * 1. modules.phase_system.phases (configuración explícita con eventos on_start/on_end)
     * 2. timing.{phase_name} (configuración legacy multi-fase)
     * 3. Fase única "main" con timer_system.round_duration o turn_system.time_limit
     *
     * @param array $config Configuración del juego
     * @return array Array de fases [{name, duration, on_start?, on_end?}, ...]
     */
    protected static function extractPhasesFromConfig(array $config): array
    {
        $timing = $config['timing'] ?? [];
        $timerSystem = $config['modules']['timer_system'] ?? [];
        $turnSystem = $config['modules']['turn_system'] ?? [];
        $phaseSystem = $config['modules']['phase_system'] ?? [];

        // PRIORIDAD 1: Si hay phase_system.phases configuradas explícitamente
        if (!empty($phaseSystem['enabled']) && !empty($phaseSystem['phases'])) {
            return $phaseSystem['phases'];
        }

        // PRIORIDAD 2: MULTI-FASE: Si hay configuración explícita de fases en timing
        $phases = [];
        $hasExplicitPhases = false;

        foreach ($timing as $key => $phaseConfig) {
            // Saltar configuraciones especiales que no son fases
            if (in_array($key, ['game_start', 'round_start', 'round_ended', 'results', 'countdown_warning_threshold'])) {
                continue;
            }

            // Si tiene duration, es una fase
            if (isset($phaseConfig['duration'])) {
                $phases[] = [
                    'name' => $key,
                    'duration' => $phaseConfig['duration']
                ];
                $hasExplicitPhases = true;
            }
        }

        if ($hasExplicitPhases && count($phases) > 0) {
            return $phases;
        }

        // 2. SINGLE-FASE: Crear fase única con nombre genérico
        // Prioridad: timer_system.round_duration > turn_system.time_limit > 30 (default)
        $duration = $timerSystem['round_duration']
            ?? $turnSystem['time_limit']
            ?? 30;

        return [
            [
                'name' => 'main',  // Nombre genérico para fase única
                'duration' => $duration
            ]
        ];
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

        // Restaurar TurnManager/PhaseManager solo si existe en el state
        $turnManager = null;
        if (isset($data['turn_system'])) {
            // Detectar si es PhaseManager (tiene 'phases' key) o TurnManager básico
            if (isset($data['turn_system']['phases'])) {
                $turnManager = PhaseManager::fromArray($data['turn_system']);
            } else {
                $turnManager = TurnManager::fromArray($data['turn_system']);
            }

            // Si existe TimerService en el state, conectarlo automáticamente al TurnManager
            if (isset($data['timer_system'])) {
                // Extraer timer_system según su estructura (puede estar anidado)
                $timerData = $data['timer_system']['timer_system'] ?? $data['timer_system'];
                $timerService = \App\Services\Modules\TimerSystem\TimerService::fromArray(['timer_system' => $timerData]);
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
            $timingConfig = $gameConfig['timing']['results'] ?? $gameConfig['timing']['round_ended'] ?? null;
            $roundPerTurn = $gameConfig['modules']['turn_system']['round_per_turn'] ?? false;

            // Si es la última ronda, NO auto-avanzar (el juego ya terminó o va a terminar)
            if ($this->currentRound >= $this->totalRounds) {
                \Log::info('[RoundManager] Last round detected, disabling auto_next', [
                    'current_round' => $this->currentRound,
                    'total_rounds' => $this->totalRounds
                ]);

                // Eliminar auto_next del timing para evitar countdown
                if ($timingConfig !== null) {
                    $timingConfig['auto_next'] = false;
                }
            }

            \Log::info('[RoundManager] Timing config', [
                'timing_config' => $timingConfig,
                'round_per_turn' => $roundPerTurn,
                'is_last_round' => $this->currentRound >= $this->totalRounds
            ]);

            // 2. Emitir evento RoundEndedEvent con timing metadata
            \Log::info("🏁 [BACKEND] Emitiendo RoundEndedEvent - Round: {$this->currentRound}");
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

    /**
     * Emitir RoundStartedEvent.
     *
     * Este método es responsable ÚNICO de emitir el evento de inicio de ronda.
     * RoundManager conoce toda la información necesaria:
     * - current_round, total_rounds (propias)
     * - phase (desde TurnManager/PhaseManager si está disponible)
     *
     * @param \App\Models\GameMatch $match Match del juego (ya filtrado si es necesario)
     * @param array|null $timing Metadata de timing (opcional)
     * @return void
     */
    public function emitRoundStartedEvent(
        \App\Models\GameMatch $match,
        ?array $timing = null
    ): void {
        // Obtener fase actual desde TurnManager si está disponible
        $phase = 'playing'; // Default

        if ($this->turnManager) {
            // Si es PhaseManager, obtener fase actual
            if ($this->turnManager instanceof \App\Services\Modules\TurnSystem\PhaseManager) {
                $phase = $this->turnManager->getCurrentPhaseName();
            }
            // Si no, usar 'playing' como default
        }

        // También intentar leer desde game_state como fallback
        if (isset($match->game_state['phase'])) {
            $phase = $match->game_state['phase'];
        }

        \Log::info("🏁 [BACKEND] Emitiendo RoundStartedEvent - Round: {$this->currentRound}/{$this->totalRounds}, Phase: {$phase}");

        event(new \App\Events\Game\RoundStartedEvent(
            match: $match,
            currentRound: $this->currentRound,
            totalRounds: $this->totalRounds,
            phase: $phase,
            timing: $timing
        ));

        // Configurar PhaseManager con match y arrancar timer
        if ($this->turnManager && $this->turnManager instanceof \App\Services\Modules\TurnSystem\PhaseManager) {
            $this->turnManager->setMatch($match);
            $this->turnManager->startTurnTimer();

            // Guardar el game_state actualizado con PhaseManager Y TimerService
            $gameState = $match->game_state;
            $gameState['turn_system'] = $this->turnManager->toArray();

            // IMPORTANTE: También guardar el TimerService actualizado con el timer nuevo
            $timerService = $this->turnManager->getTimerService();
            if ($timerService) {
                $timerData = $timerService->toArray();
                // toArray() devuelve ['timer_system' => ...], extraer solo la parte interna
                $gameState['timer_system'] = $timerData['timer_system'] ?? [];
            }

            $match->game_state = $gameState;
            $match->save();
        }
    }

    /**
     * Iniciar timer de ronda automáticamente si está configurado.
     *
     * Este método gestiona el timer de ronda:
     * - Lee la configuración desde game_state
     * - Crea timer con RoundTimerExpiredEvent configurado
     * - Conoce current_round directamente (propiedad propia)
     * - Auto-guarda el timer en game_state
     *
     * IMPORTANTE: RoundManager es responsable de gestionar timers de ronda,
     * no BaseGameEngine. Esto mantiene la separación de responsabilidades.
     *
     * @param \App\Models\GameMatch $match Match del juego
     * @param \App\Services\Modules\TimerSystem\TimerService $timerService Servicio de timers
     * @param array $config Configuración del juego (game_state['_config'])
     * @param string $timerName Nombre del timer (default: 'round')
     * @return bool True si se inició timer, false si no está configurado
     */
    public function startRoundTimer(
        \App\Models\GameMatch $match,
        \App\Services\Modules\TimerSystem\TimerService $timerService,
        array $config,
        string $timerName = 'round'
    ): bool {
        // Buscar duración del timer en configuración
        $duration = $config['modules']['timer_system']['round_duration'] ?? null;

        if ($duration === null || $duration <= 0) {
            return false;
        }

        \Log::info('[RoundManager] Starting round timer', [
            'match_id' => $match->id,
            'timer_name' => $timerName,
            'duration' => $duration,
            'current_round' => $this->currentRound
        ]);

        // Configurar timer para emitir RoundTimerExpiredEvent cuando expire
        // IMPORTANTE: Solo pasar match_id, NO el match completo (evita payload gigante)
        $timerService->startTimer(
            timerName: $timerName,
            durationSeconds: $duration,
            eventToEmit: \App\Events\Game\RoundTimerExpiredEvent::class,
            eventData: [$match->id, $this->currentRound, $timerName],
            restart: true
        );

        return true;
    }
}

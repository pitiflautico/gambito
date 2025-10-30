<?php

namespace App\Contracts;

use App\Contracts\Strategies\EndRoundStrategy;
use App\Contracts\Strategies\FreeEndStrategy;
use App\Contracts\Strategies\SequentialEndStrategy;
use App\Contracts\Strategies\SimultaneousEndStrategy;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Modules\RoundSystem\RoundManager;
use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;
use App\Services\Modules\ScoringSystem\ScoreManager;
use App\Services\Modules\TimerSystem\TimerService;
use Illuminate\Support\Facades\Log;

/**
 * Clase base abstracta para todos los Engines de juegos.
 *
 * RESPONSABILIDAD: Coordinar entre la lógica del juego y los módulos del sistema.
 *
 * SEPARACIÓN DE RESPONSABILIDADES:
 * ================================
 *
 * 1. LÓGICA DEL JUEGO (métodos abstractos - cada juego implementa)
 *    - processRoundAction()     : ¿Qué pasa cuando un jugador actúa?
 *    - startNewRound()          : ¿Cómo se inicia una nueva ronda?
 *    - endCurrentRound()        : ¿Qué pasa al terminar una ronda?
 *    - getAllPlayerResults()    : Resultados de todos los jugadores
 *
 * 2. COORDINACIÓN CON MÓDULOS (métodos concretos - ya implementados aquí)
 *    - Strategy Pattern para decidir cuándo terminar según modo
 *    - Helpers para trabajar con módulos (RoundManager, ScoreManager, etc.)
 *    - Programación de siguiente ronda vía RoundManager
 *
 * 3. EXTENSIBILIDAD (Strategy Pattern)
 *    - Cada modo tiene su propia estrategia de finalización
 *    - Los juegos pueden sobrescribir getEndRoundStrategy()
 *    - Soporta modos custom y múltiples fases
 *
 * DESACOPLAMIENTO:
 * ================
 * - El Engine NO decide cuándo terminar (lo hace la Strategy + RoundManager)
 * - El Engine NO gestiona turnos directamente (lo hace TurnManager vía RoundManager)
 * - El Engine NO programa delays manualmente (lo hace RoundManager)
 * - El Engine SOLO define qué pasa en cada ronda (lógica del juego)
 *
 * CONVENCIONES DE VISTAS:
 * ======================
 * - Vista principal del juego: games/{slug}/views/game.blade.php
 * - NUNCA usar canvas.blade.php, siempre game.blade.php
 * - El controlador busca la vista como: {slug}::game
 *
 * @see docs/ENGINE_ARCHITECTURE.md
 * @see docs/strategies/END_ROUND_STRATEGIES.md
 */
abstract class BaseGameEngine implements GameEngineInterface
{
    // ========================================================================
    // MÉTODOS ABSTRACTOS: Cada juego debe implementar su lógica específica
    // ========================================================================

    /**
     * Procesar la acción de un jugador en la ronda actual.
     *
     * Este método NO debe decidir si la ronda termina o no.
     * Solo debe procesar la acción y retornar el resultado.
     *
     * @param GameMatch $match
     * @param Player $player
     * @param array $data
     * @return array ['success' => bool, 'player_id' => int, 'data' => mixed]
     */
    abstract protected function processRoundAction(GameMatch $match, Player $player, array $data): array;

    /**
     * Hook: Preparar datos específicos de la nueva ronda.
     *
     * Este método OPCIONAL permite a los juegos cargar datos específicos
     * para la nueva ronda (ej: siguiente pregunta en Trivia, palabra en Pictionary).
     *
     * BaseGameEngine ya ha hecho todo lo común (resetear PlayerManager, timers, etc.)
     * ANTES de llamar a este hook.
     *
     * @param GameMatch $match
     * @return void
     */
    protected function onRoundStarting(GameMatch $match): void
    {
        // Implementación vacía por defecto
        // Los juegos pueden sobrescribir para cargar datos específicos
    }

    /**
     * Verificar el estado actual de la ronda según las reglas del juego.
     * 
     * Este método OPCIONAL permite al juego analizar si la ronda debe terminar
     * basándose en el estado actual (útil para auditorías o debugging).
     * 
     * La decisión real de terminar ronda se toma en processRoundAction() 
     * mediante el flag force_end.
     * 
     * @param GameMatch $match
     * @return array ['should_end' => bool, 'reason' => string|null]
     */
    protected function checkRoundState(GameMatch $match): array
    {
        // Implementación por defecto: no hace nada
        return ['should_end' => false, 'reason' => null];
    }

    /**
     * Hook específico del juego para iniciar el juego.
     *
     * Los juegos implementan este método para setear su estado inicial específico
     * (ej: cargar primera pregunta, asignar roles, etc.)
     *
     * @param GameMatch $match
     * @return void
     */
    abstract protected function onGameStart(GameMatch $match): void;

    /**
     * Hook cuando el timer de ronda expira.
     *
     * IMPLEMENTACIÓN POR DEFECTO:
     * - Llama a hook opcional beforeTimerExpiredAdvance() para lógica específica del juego
     * - Completa la ronda actual (completeRound)
     * - El timer se limpia automáticamente al avanzar
     *
     * Los juegos pueden:
     * 1. Usar implementación por defecto (no hacer nada)
     * 2. Sobrescribir beforeTimerExpiredAdvance() para lógica específica
     * 3. Sobrescribir completamente onRoundTimerExpired() si necesitan otro comportamiento
     *    (ej: Pictionary puede pasar turno en lugar de completar ronda)
     *
     * @param GameMatch $match
     * @param string $timerName Nombre del timer que expiró (ej: 'round', 'turn')
     * @return void
     */
    protected function onRoundTimerExpired(GameMatch $match, string $timerName = 'round'): void
    {
        Log::info("[{$this->getGameSlug()}] Timer expired - default handler", [
            'match_id' => $match->id,
            'timer_name' => $timerName
        ]);

        // 1. Hook opcional para lógica específica del juego antes de avanzar
        $this->beforeTimerExpiredAdvance($match, $timerName);

        // 2. Comportamiento por defecto: completar ronda y avanzar
        // El timer se limpia automáticamente en completeRound → handleNewRound
        $results = [
            'reason' => 'timer_expired',
            'message' => '¡Tiempo agotado!'
        ];

        $this->completeRound($match, $results);

        Log::info("[{$this->getGameSlug()}] Round completed due to timer expiration", [
            'match_id' => $match->id
        ]);
    }

    /**
     * Hook opcional ejecutado ANTES de completar ronda por timer expiration.
     *
     * Los juegos pueden sobrescribir este método para:
     * - Registrar estadísticas específicas
     * - Modificar puntuación por timeout
     * - Emitir eventos custom
     * - Etc.
     *
     * NO debe avanzar la ronda, solo preparar o modificar estado.
     *
     * @param GameMatch $match
     * @param string $timerName
     * @return void
     */
    protected function beforeTimerExpiredAdvance(GameMatch $match, string $timerName = 'round'): void
    {
        // Implementación vacía por defecto - juegos la sobrescriben si necesitan
    }

    /**
     * Hook cuando un jugador se desconecta DURANTE la partida.
     *
     * COMPORTAMIENTO POR DEFECTO:
     * - Pausar timer de ronda si existe
     * - Marcar juego como pausado
     * - Llamar hook beforePlayerDisconnectedPause()
     * - Emitir PlayerDisconnectedEvent
     *
     * Los juegos pueden:
     * 1. Usar comportamiento por defecto (pausa automática)
     * 2. Sobrescribir beforePlayerDisconnectedPause() para lógica específica
     * 3. Sobrescribir completamente onPlayerDisconnected() si necesitan otro comportamiento
     *    (ej: continuar sin el jugador, AI replacement, etc.)
     *
     * @param GameMatch $match
     * @param Player $player Jugador que se desconectó
     * @return void
     */
    public function onPlayerDisconnected(GameMatch $match, \App\Models\Player $player): void
    {
        Log::info("[{$this->getGameSlug()}] Player disconnected - pausing game", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'player_name' => $player->name,
        ]);

        // 1. Hook opcional antes de pausar
        $this->beforePlayerDisconnectedPause($match, $player);

        // 2. Obtener game_state como array para modificarlo
        $gameState = $match->game_state;

        // 3. Marcar juego como pausado
        $gameState['paused'] = true;
        $gameState['paused_reason'] = 'player_disconnected';
        $gameState['disconnected_player_id'] = $player->id;
        $gameState['paused_at'] = now()->toDateTimeString();

        // 4. Guardar estado
        $match->game_state = $gameState;
        $match->save();

        // 5. Emitir evento de desconexión (el frontend pausará los timers visuales automáticamente)
        event(new \App\Events\Game\PlayerDisconnectedEvent($match, $player));

        Log::info("[{$this->getGameSlug()}] Game paused due to disconnection", [
            'match_id' => $match->id,
        ]);
    }

    /**
     * Hook cuando un jugador se reconecta DURANTE la partida.
     *
     * COMPORTAMIENTO POR DEFECTO:
     * - Resumir/reiniciar timer
     * - Marcar juego como activo
     * - Llamar hook afterPlayerReconnected()
     * - Reiniciar ronda actual (resetear locks, nuevo timer)
     * - Emitir PlayerReconnectedEvent
     *
     * Los juegos pueden:
     * 1. Usar comportamiento por defecto (reinicio de ronda)
     * 2. Sobrescribir afterPlayerReconnected() para lógica específica
     * 3. Sobrescribir completamente onPlayerReconnected() si necesitan otro comportamiento
     *    (ej: solo resumir sin reiniciar ronda)
     *
     * @param GameMatch $match
     * @param Player $player Jugador que se reconectó
     * @return void
     */
    public function onPlayerReconnected(GameMatch $match, \App\Models\Player $player): void
    {
        Log::info("[{$this->getGameSlug()}] Player reconnected - resuming game", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'player_name' => $player->name,
        ]);

        // 1. Obtener game_state como array para modificarlo
        $gameState = $match->game_state;

        // 2. Marcar juego como activo (quitar pausa)
        $gameState['paused'] = false;
        unset($gameState['paused_reason']);
        unset($gameState['disconnected_player_id']);
        unset($gameState['paused_at']);

        // 3. Asignar de vuelta y guardar
        $match->game_state = $gameState;
        $match->save();

        // 2. Hook opcional después de reconectar
        $this->afterPlayerReconnected($match, $player);

        // 3. Comportamiento por defecto: Reiniciar ronda actual
        // Esto garantiza que todos empiecen de cero con el jugador reconectado
        $shouldRestartRound = true;

        if ($shouldRestartRound) {
            Log::info("[{$this->getGameSlug()}] Restarting current round", [
                'match_id' => $match->id,
                'round' => $match->game_state['round_system']['current_round'] ?? null,
            ]);

            // Reiniciar ronda: llama startNewRound() del juego + inicia nuevo timer
            $this->handleNewRound($match, advanceRound: false);
        }

        // 4. Emitir evento (broadcast a todos los clientes)
        event(new \App\Events\Game\PlayerReconnectedEvent($match, $player, $shouldRestartRound));

        Log::info("[{$this->getGameSlug()}] Game resumed after reconnection", [
            'match_id' => $match->id,
        ]);
    }

    /**
     * Hook opcional ejecutado ANTES de pausar el juego por desconexión.
     *
     * Los juegos pueden sobrescribir este método para:
     * - Guardar estado temporal
     * - Registrar estadísticas
     * - Notificar a otros jugadores
     * - Preparar UI de pausa
     *
     * NO debe modificar el estado de pausa, solo preparar.
     *
     * @param GameMatch $match
     * @param Player $player
     * @return void
     */
    protected function beforePlayerDisconnectedPause(GameMatch $match, \App\Models\Player $player): void
    {
        // Implementación vacía por defecto - juegos la sobrescriben si necesitan
    }

    /**
     * Hook opcional ejecutado DESPUÉS de reconectar y quitar pausa.
     *
     * Los juegos pueden sobrescribir este método para:
     * - Restaurar estado temporal
     * - Registrar estadísticas de reconexión
     * - Compensar tiempo perdido
     *
     * Se ejecuta ANTES de reiniciar la ronda.
     *
     * @param GameMatch $match
     * @param Player $player
     * @return void
     */
    protected function afterPlayerReconnected(GameMatch $match, \App\Models\Player $player): void
    {
        // Implementación vacía por defecto - juegos la sobrescriben si necesitan
    }

    // ========================================================================
    // IMPLEMENTACIÓN BASE: startGame (común para todos los juegos)
    // ========================================================================

    /**
     * Iniciar/Reiniciar el juego - IMPLEMENTACIÓN BASE.
     *
     * FLUJO HÍBRIDO (Sistema Nuevo):
     * ================================
     * 1. Lobby → Master presiona "Iniciar" → GameMatch::start()
     * 2. Backend emite GameStartedEvent → Todos redirigen a /rooms/{code}/transition
     * 3. Transition → Presence Channel trackea conexiones
     * 4. Todos conectados → apiReady() emite GameCountdownEvent (timestamp-based)
     * 5. Countdown termina → apiInitializeEngine() llama:
     *    - engine->initialize($match) - UNA VEZ (guarda config en _config)
     *    - engine->startGame($match) - Resetea módulos + llama onGameStart()
     * 6. onGameStart() - Setea estado inicial + emite RoundStartedEvent
     * 7. GameMatch emite GameInitializedEvent → Todos redirigen al juego
     *
     * Este método NO debe ser sobrescrito por los juegos.
     * Los juegos solo implementan onGameStart() para su lógica específica.
     *
     * @param GameMatch $match
     * @return void
     */
    public function startGame(GameMatch $match): void
    {
        Log::info("[{$this->getGameSlug()}] Starting game (resetting modules + calling onGameStart)", [
            'match_id' => $match->id
        ]);

        // 1. Resetear módulos automáticamente según config.json
        $this->resetModules($match);

        Log::info("[{$this->getGameSlug()}] Modules reset complete", [
            'match_id' => $match->id
        ]);

        // 2. Llamar al hook específico del juego
        // onGameStart() debe setear estado inicial y emitir la primera ronda
        $this->onGameStart($match);

        Log::info("[{$this->getGameSlug()}] onGameStart() completed", [
            'match_id' => $match->id,
            'phase' => $match->game_state['phase'] ?? 'unknown'
        ]);
    }

    /**
     * Manejar el inicio de una nueva ronda - MÉTODO BASE.
     *
     * Este método orquesta el inicio de cada ronda y ejecuta tareas automáticas:
     * 1. Avanzar contador de ronda y resetear turnos/timer (via RoundManager)
     * 2. Guardar scores actualizados (si scoring_system habilitado)
     * 3. Llamar a startNewRound() del juego específico (lógica del juego)
     * 4. Emitir RoundStartedEvent con timing metadata
     *
     * CUÁNDO SE LLAMA:
     * - Al inicio del juego: Desde onGameStart() para la primera ronda (advanceRound: false)
     * - Entre rondas: Desde el endpoint /api/rooms/{code}/next-round (advanceRound: true)
     *
     * FLUJO DE EVENTOS:
     * 1. Ronda termina → Engine llama endCurrentRound()
     * 2. endCurrentRound() emite RoundEndedEvent con timing: {auto_next: true, delay: 3}
     * 3. Frontend escucha .game.round.ended, espera 3 segundos
     * 4. Frontend llama a POST /api/rooms/{code}/next-round
     * 5. Endpoint llama a handleNewRound(advanceRound: true)
     * 6. handleNewRound() → RoundManager avanza ronda y resetea timer
     *
     * @param GameMatch $match
     * @param bool $advanceRound Si debe avanzar el contador de ronda (false para primera ronda)
     * @return void
     */
    public function handleNewRound(GameMatch $match, bool $advanceRound = true): void
    {
        // RACE CONDITION PROTECTION: Re-leer match desde BD antes de procesar
        // Esto evita que múltiples requests procesen la misma ronda
        $match->refresh();

        $currentRound = $match->game_state['round_system']['current_round'] ?? 1;

        Log::info("[{$this->getGameSlug()}] Handling new round", [
            'match_id' => $match->id,
            'current_round' => $currentRound,
            'advance_round' => $advanceRound
        ]);

        // 0. VERIFICAR SI EL JUEGO YA TERMINÓ (antes de avanzar)
        if ($this->isModuleEnabled($match, 'round_system') && $advanceRound) {
            $roundManager = $this->getRoundManager($match);
            
            // Si ya se completó la última ronda, finalizar en vez de avanzar
            if ($roundManager->isGameComplete()) {
                Log::info("[{$this->getGameSlug()}] Game already complete, finalizing instead of starting new round", [
                    'match_id' => $match->id,
                    'current_round' => $roundManager->getCurrentRound(),
                    'total_rounds' => $roundManager->getTotalRounds()
                ]);
                
                $this->finalize($match);
                return;
            }
        }

        // 1. LÓGICA BASE: Avanzar ronda (via RoundManager)
        // RoundManager se encarga de:
        // - Incrementar contador de ronda
        // - Limpiar eliminaciones temporales
        // - Resetear TurnManager (que cancela timer anterior e inicia uno nuevo)
        if ($this->isModuleEnabled($match, 'round_system') && $advanceRound) {
            $roundManager = $this->getRoundManager($match);
            $roundManager->advanceToNextRound();
            $this->saveRoundManager($match, $roundManager);

            Log::info("[{$this->getGameSlug()}] Round advanced by RoundManager", [
                'new_round' => $roundManager->getCurrentRound()
            ]);
        }

        // 2. LÓGICA BASE: Guardar scores actualizados
        if ($this->isModuleEnabled($match, 'scoring_system')) {
            // Los scores ya están actualizados en game_state por endCurrentRound()
            // Solo verificamos que estén sincronizados
            $match->refresh();

            Log::info("[{$this->getGameSlug()}] Scores synchronized", [
                'scores' => $match->game_state['scoring_system']['scores'] ?? []
            ]);
        }

        // 2.1. LÓGICA BASE: Resetear PlayerManager (desbloquear jugadores, limpiar acciones)
        // Esto debe hacerse ANTES de onRoundStarting() para que los juegos ya tengan
        // los jugadores listos para la nueva ronda
        if ($this->isModuleEnabled($match, 'player_system')) {
            $playerManager = $this->getPlayerManager($match, $this->scoreCalculator ?? null);
            $playerManager->reset($match);  // Emite PlayersUnlockedEvent automáticamente
            $this->savePlayerManager($match, $playerManager);

            Log::info("[{$this->getGameSlug()}] PlayerManager reset - players unlocked", [
                'match_id' => $match->id
            ]);
        }

        // 3. HOOK: Permitir al juego preparar datos específicos (ej: cargar pregunta)
        // Este es un hook OPCIONAL, no todos los juegos necesitan usarlo
        $this->onRoundStarting($match);

        // 3.1. Iniciar timer de ronda automáticamente (si está configurado)
        // DELEGADO A ROUNDMANAGER: Los módulos gestionan sus propios timers
        if ($this->isModuleEnabled($match, 'round_system') && $this->isModuleEnabled($match, 'timer_system')) {
            $roundManager = $this->getRoundManager($match);
            $timerService = $this->getTimerService($match);
            $config = $match->game_state['_config'] ?? [];

            if ($roundManager->startRoundTimer($match, $timerService, $config)) {
                // Timer iniciado, guardar en game_state
                $this->saveTimerService($match, $timerService);
                Log::info("[{$this->getGameSlug()}] Round timer started by RoundManager");
            }
        }

        // 4. Filtrar game_state para remover información sensible
        $filteredGameState = $this->filterGameStateForBroadcast($match->game_state, $match);

        // 5. Crear copia temporal del match con game_state filtrado para el evento
        $matchForEvent = clone $match;
        $matchForEvent->game_state = $filteredGameState;

        // 6. Obtener timing metadata del config del juego (si existe)
        $timing = $this->getRoundStartTiming($match);

        // 7. Obtener RoundManager
        $roundManager = $this->getRoundManager($match);

        // 8. Delegar emisión del evento a RoundManager
        // RoundManager conoce TODA la información necesaria:
        // - current_round, total_rounds (propias)
        // - phase (desde TurnManager/PhaseManager o game_state)
        // Solo necesitamos pasarle el timing personalizado del juego
        $roundManager->emitRoundStartedEvent(
            match: $matchForEvent,
            timing: $timing
        );

        // 9. Llamar al hook para que el juego ejecute lógica custom después del evento
        $currentRound = $roundManager->getCurrentRound();
        $totalRounds = $roundManager->getTotalRounds();
        $this->onRoundStarted($match, $currentRound, $totalRounds);
    }

    /**
     * Hook: Ejecutado DESPUÉS de emitir RoundStartedEvent.
     *
     * Los juegos pueden sobrescribir este método para ejecutar lógica custom
     * después de que la ronda haya empezado y el evento haya sido emitido.
     *
     * Ejemplos de uso:
     * - Iniciar timers específicos del juego
     * - Enviar notificaciones privadas a jugadores
     * - Ejecutar lógica de negocio específica del juego
     *
     * @param GameMatch $match
     * @param int $currentRound
     * @param int $totalRounds
     * @return void
     */
    protected function onRoundStarted(GameMatch $match, int $currentRound, int $totalRounds): void
    {
        // Implementación vacía por defecto
        // Los juegos específicos pueden sobrescribir este método
    }

    /**
     * Hook: Ejecutado DESPUÉS de emitir RoundEndedEvent.
     *
     * Los juegos pueden sobrescribir este método para ejecutar lógica custom
     * después de que la ronda haya terminado y el evento haya sido emitido.
     *
     * Ejemplos de uso:
     * - Cancelar timers específicos del juego
     * - Calcular estadísticas de la ronda
     * - Preparar datos para la siguiente ronda
     * - Ejecutar lógica de negocio específica del juego
     *
     * @param GameMatch $match
     * @param int $roundNumber Número de la ronda que terminó
     * @param array $results Resultados de la ronda
     * @param array $scores Puntuaciones actuales
     * @return void
     */
    protected function onRoundEnded(GameMatch $match, int $roundNumber, array $results, array $scores): void
    {
        // Implementación vacía por defecto
        // Los juegos específicos pueden sobrescribir este método
    }

    // ========================================================================
    // PHASE LIFECYCLE HOOKS
    // ========================================================================

    /**
     * Manejar el fin de una fase (llamado por PhaseEndedEvent).
     *
     * FLUJO AUTOMÁTICO:
     * 1. Llama al hook onPhaseEnded() (puede ser override por engines locales)
     * 2. Si onPhaseEnded() retorna true o no está override → auto-avanza a siguiente fase
     * 3. Si onPhaseEnded() retorna false → el engine local se encarga del avance
     *
     * @param GameMatch $match
     * @param array $phaseConfig Configuración de la fase que terminó
     * @return void
     */
    public function handlePhaseEnded(GameMatch $match, array $phaseConfig): void
    {
        $phaseName = $phaseConfig['name'] ?? 'unknown';

        \Log::info("🎬 [BaseGameEngine] handlePhaseEnded()", [
            'phase' => $phaseName,
            'match_id' => $match->id,
            'engine' => get_class($this)
        ]);

        // HOOK: Permitir que el engine local maneje el fin de fase
        $shouldAutoAdvance = $this->onPhaseEnded($match, $phaseConfig);

        // Si el hook no maneja el avance, hacerlo automáticamente
        if ($shouldAutoAdvance !== false) {
            \Log::info("🔄 [BaseGameEngine] Auto-advancing to next phase", [
                'phase' => $phaseName
            ]);

            // Obtener PhaseManager y avanzar
            if ($this->isModuleEnabled($match, 'phase_system')) {
                $roundManager = $this->getRoundManager($match);
                $phaseManager = $roundManager->getTurnManager();

                if ($phaseManager && $phaseManager instanceof \App\Services\Modules\TurnSystem\PhaseManager) {
                    $nextPhaseInfo = $phaseManager->nextPhase();

                    // Si el ciclo se completó, la ronda terminó
                    if ($nextPhaseInfo['cycle_completed']) {
                        \Log::info("🏁 [BaseGameEngine] Phase cycle completed - ending round", [
                            'current_round' => $roundManager->getCurrentRound()
                        ]);

                        // Finalizar ronda actual
                        $this->endCurrentRound($match);
                    }

                    // Guardar estado actualizado
                    $this->saveRoundManager($match, $roundManager);
                } else {
                    \Log::warning("⚠️ [BaseGameEngine] PhaseManager not available for auto-advance");
                }
            }
        }
    }

    /**
     * Hook: Cuando termina una fase.
     *
     * Los engines locales pueden override este método para ejecutar lógica específica.
     *
     * @param GameMatch $match
     * @param array $phaseConfig Configuración de la fase que terminó
     * @return bool|null Retornar false para prevenir auto-avance, true/null para permitirlo
     */
    protected function onPhaseEnded(GameMatch $match, array $phaseConfig): ?bool
    {
        // Implementación vacía por defecto - permite auto-avance
        return true;
    }

    /**
     * Hook: Cuando inicia una fase.
     *
     * Los engines locales pueden override este método para ejecutar lógica específica.
     *
     * @param GameMatch $match
     * @param array $phaseConfig Configuración de la fase que inicia
     * @return void
     */
    protected function onPhaseStarted(GameMatch $match, array $phaseConfig): void
    {
        // Implementación vacía por defecto
    }

    /**
     * Obtener timing metadata para el inicio de ronda.
     *
     * Los juegos pueden sobrescribir este método para proporcionar timing específico.
     * Por defecto, busca en el config.json del juego.
     *
     * IMPORTANTE: Siempre incluye `server_time` (timestamp UNIX) para sincronización
     * y cálculo de elapsed time en el frontend.
     *
     * @param GameMatch $match
     * @return array|null Timing metadata o null si no hay timing
     */
    protected function getRoundStartTiming(GameMatch $match): ?array
    {
        $timing = null;

        // Intentar obtener del config del juego
        $config = $match->game_state['_config'] ?? [];

        // Buscar en timing config si existe
        if (isset($config['timing']['round_start'])) {
            $timing = $config['timing']['round_start'];
        }

        // Timing por defecto para juegos con timer_system (ej: Trivia)
        if (!$timing) {
            $gameConfig = $this->getGameConfig();
            $timerConfig = $gameConfig['modules']['timer_system'] ?? [];

            Log::debug("[{$this->getGameSlug()}] Checking timer_system config", [
                'has_modules' => isset($gameConfig['modules']),
                'has_timer_system' => isset($gameConfig['modules']['timer_system']),
                'timer_config' => $timerConfig
            ]);

            if (isset($timerConfig['enabled']) && $timerConfig['enabled'] &&
                isset($timerConfig['round_duration']) && $timerConfig['round_duration'] > 0) {
                $timing = [
                    'duration' => $timerConfig['round_duration'],
                    'countdown_visible' => true,
                    'warning_threshold' => 3
                ];

                Log::info("[{$this->getGameSlug()}] Timer system timing created", $timing);
            }
        }

        // Timing por defecto para juegos con turn_system
        if (!$timing) {
            $turnSystem = $match->game_state['turn_system'] ?? [];
            if (isset($turnSystem['time_limit']) && $turnSystem['time_limit'] > 0) {
                $timing = [
                    'duration' => $turnSystem['time_limit'],
                    'countdown_visible' => true,
                    'warning_threshold' => 3
                ];
            }
        }

        // SIEMPRE agregar server_time si hay timing
        // Esto permite al frontend calcular elapsed time para scoring por rapidez
        if ($timing) {
            $timing['server_time'] = microtime(true);
        }

        return $timing;
    }

    /**
     * Filtrar game_state antes de enviarlo en eventos broadcast.
     *
     * Algunos juegos necesitan ocultar información sensible en el game_state
     * antes de enviarlo a todos los jugadores (ej: palabras secretas, respuestas correctas).
     * Los juegos pueden sobrescribir este método para remover información sensible.
     *
     * Por defecto, retorna el game_state sin cambios.
     *
     * @param array $gameState El game_state completo
     * @param GameMatch $match El match actual
     * @return array El game_state filtrado
     */
    protected function filterGameStateForBroadcast(array $gameState, GameMatch $match): array
    {
        // Por defecto, no filtrar nada
        return $gameState;
    }

    // ========================================================================
    // STRATEGY PATTERN: Extensibilidad para diferentes modos
    // ========================================================================

    /**
     * Obtener la estrategia de finalización según el modo de juego.
     *
     * Los juegos pueden sobrescribir este método para:
     * - Usar estrategias custom
     * - Configurar estrategias con opciones específicas
     * - Cambiar de estrategia según la fase del juego
     *
     * @param string $turnMode Modo de turnos: 'sequential', 'simultaneous', 'free', 'shuffle'
     * @return EndRoundStrategy
     */
    protected function getEndRoundStrategy(string $turnMode): EndRoundStrategy
    {
        return match ($turnMode) {
            'simultaneous' => new SimultaneousEndStrategy(),
            'sequential' => new SequentialEndStrategy(),
            'shuffle' => new SequentialEndStrategy(), // Shuffle usa lógica sequential
            'free' => new FreeEndStrategy(),
            default => throw new \InvalidArgumentException("Unsupported turn mode: {$turnMode}"),
        };
    }

    // ========================================================================
    // COORDINACIÓN: Orquestación del flujo de juego
    // ========================================================================

    /**
     * Procesar una acción de un jugador.
     *
     * Este método coordina entre la lógica del juego y los módulos.
     * Soporta diferentes modos de juego automáticamente vía Strategy Pattern.
     *
     * FLUJO:
     * 1. Procesar acción específica del juego
     * 2. Detectar modo y obtener estrategia de finalización
     * 3. Consultar a la estrategia si debe terminar
     * 4. Si termina: finalizar y programar siguiente
     * 5. Retornar resultado
     *
     * @param GameMatch $match
     * @param Player $player
     * @param string $action
     * @param array $data
     * @return array
     */
    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        Log::info("[{$this->getGameSlug()}] Processing action", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'action' => $action
        ]);

        // 1. Procesar acción específica del juego
        $data['action'] = $action;
        $actionResult = $this->processRoundAction($match, $player, $data);

        // 2. Obtener RoundManager y detectar modo
        $roundManager = $this->getRoundManager($match);
        $turnMode = $roundManager->getTurnManager()->getMode();

        // 3. Obtener estrategia de finalización según modo
        $strategy = $this->getEndRoundStrategy($turnMode);

        // 4. Consultar a la estrategia si debe terminar
        $roundStatus = $strategy->shouldEnd(
            $match,
            $actionResult,
            $roundManager,
            fn($match) => $this->getAllPlayerResults($match)
        );

        // 5. Actuar según decisión de la estrategia
        if ($roundStatus['should_end']) {
            Log::info("[{$this->getGameSlug()}] Round/Turn ending", [
                'match_id' => $match->id,
                'mode' => $turnMode,
                'reason' => $roundStatus['reason'] ?? 'strategy_decided'
            ]);

            // Finalizar ronda/turno actual
            $this->endCurrentRound($match);

            // NOTA: No programamos automáticamente la siguiente ronda aquí.
            // Cada juego decide cómo avanzar (algunos usan delay en backend, otros en frontend).
            // Los juegos pueden sobrescribir processAction para custom behavior.
        }

        // 6. Retornar resultado con información adicional
        return array_merge($actionResult, [
            'round_status' => $roundStatus,
            'turn_mode' => $turnMode,
        ]);
    }


    /**
     * Finalizar ronda actual (GENÉRICO para todos los juegos).
     *
     * Este método implementa el flujo completo de finalización:
     * 1. Obtiene resultados via getRoundResults() (implementado por cada juego)
     * 2. Llama a completeRound() que maneja todo el resto
     *
     * Los juegos NO necesitan implementar endCurrentRound(), solo getRoundResults().
     *
     * @param GameMatch $match
     * @return void
     */
    public function endCurrentRound(GameMatch $match): void
    {
        Log::info("[{$this->getGameSlug()}] Ending current round", ['match_id' => $match->id]);

        // 1. Obtener resultados del juego (cada juego implementa su propia lógica)
        $results = $this->getRoundResults($match);

        // 2. Completar ronda (emitir eventos, avanzar, etc.)
        $this->completeRound($match, $results);

        Log::info("[{$this->getGameSlug()}] Round ended", [
            'match_id' => $match->id,
            'winner_id' => $results['winner_id'] ?? null
        ]);
    }

    /**
     * Obtener resultados de la ronda actual.
     *
     * Cada juego implementa su propia lógica para recopilar resultados:
     * - Quién respondió/jugó
     * - Quién ganó/acertó
     * - Puntos obtenidos
     * - Datos específicos del juego
     *
     * @param GameMatch $match
     * @return array Resultados de la ronda
     */
    abstract protected function getRoundResults(GameMatch $match): array;

    /**
     * Completar la ronda actual y avanzar a la siguiente.
     *
     * MÉTODO INTERNO - Los juegos deben usar endCurrentRound() en su lugar.
     * Coordina todo el flujo de finalización y avance de rondas:
     *
     * 1. Emite RoundEndedEvent (con resultados del juego específico)
     * 2. Avanza RoundManager
     * 3. Verifica si el juego terminó
     * 4. Si no terminó:
     *    - Llama a startNewRound() (implementado por el juego)
     *    - Emite RoundStartedEvent
     *
     * IMPORTANTE: Los juegos NO deben:
     * - Avanzar RoundManager manualmente
     * - Emitir RoundEndedEvent o RoundStartedEvent manualmente
     * - Llamar a nextRound() o nextTurn() directamente
     *
     * @param GameMatch $match
     * @param array $results Resultados de la ronda (calculados por el juego específico)
     * @return void
     */
    protected function completeRound(GameMatch $match, array $results = []): void
    {
        Log::info("[{$this->getGameSlug()}] Completing round", [
            'match_id' => $match->id,
        ]);

        // 1. Obtener módulos
        $roundManager = $this->getRoundManager($match);
        $scores = $this->getScores($match->game_state);

        Log::info("[{$this->getGameSlug()}] About to call roundManager->completeRound()", [
            'match_id' => $match->id,
            'has_roundManager' => $roundManager !== null
        ]);

        // 2. Delegar a RoundManager para completar la ronda
        // RoundManager maneja: emitir evento, avanzar, programar backup, etc.
        $roundManager->completeRound($match, $results, $scores);

        Log::info("[{$this->getGameSlug()}] roundManager->completeRound() finished");

        // 3. Llamar al hook para que el juego ejecute lógica custom después del evento
        $currentRound = $roundManager->getCurrentRound();
        $this->onRoundEnded($match, $currentRound, $results, $scores);

        // 4. Guardar estado actualizado
        $this->saveRoundManager($match, $roundManager);

        // 5. Verificar si el juego terminó
        if ($roundManager->isGameComplete()) {
            Log::info("[{$this->getGameSlug()}] Game complete, finalizing", [
                'match_id' => $match->id,
            ]);
            $this->finalize($match);
            return;
        }
    }

    // ========================================================================
    // INICIALIZACIÓN Y RESET DE MÓDULOS
    // ========================================================================

    /**
     * Inicializar módulos según la configuración del juego.
     *
     * Lee el config.json del juego para ver qué módulos están habilitados
     * y los crea con su configuración inicial.
     *
     * Este método se llama desde initialize() para crear los módulos por primera vez.
     *
     * @param GameMatch $match
     * @param array $moduleOverrides Configuración custom para módulos específicos
     * @return void
     */
    protected function initializeModules(GameMatch $match, array $moduleOverrides = []): void
    {
        // Cargar config.json del juego
        $gameSlug = $match->room->game->slug;
        $configPath = base_path("games/{$gameSlug}/config.json");

        if (!file_exists($configPath)) {
            throw new \RuntimeException("Game config.json not found for: {$gameSlug}");
        }

        $gameConfig = json_decode(file_get_contents($configPath), true);
        $modules = $gameConfig['modules'] ?? [];
        $playerIds = $match->players->pluck('id')->toArray();

        Log::info("Initializing modules for game", [
            'match_id' => $match->id,
            'game' => $gameSlug,
            'enabled_modules' => array_keys(array_filter($modules, fn($m) => $m['enabled'] ?? false))
        ]);

        $gameState = [];

        // ========================================================================
        // MÓDULO: Timer System (crear PRIMERO para que otros módulos lo usen)
        // ========================================================================
        if ($modules['timer_system']['enabled'] ?? false) {
            $timerService = new TimerService();
            Log::debug("Timer system initialized");
        }

        // ========================================================================
        // MÓDULO: Round System (incluye Turn System y Phase System internamente)
        // ========================================================================
        if ($modules['round_system']['enabled'] ?? false) {
            $roundConfig = $modules['round_system'];
            $totalRounds = $moduleOverrides['round_system']['total_rounds'] ?? $roundConfig['total_rounds'] ?? 10;

            // RoundManager se auto-inicializa con configuración completa
            // Internamente crea PhaseManager con las fases del config
            $roundManager = RoundManager::createFromConfig(
                config: $gameConfig,
                playerIds: $playerIds,
                totalRounds: $totalRounds
            );

            // Conectar TimerService al PhaseManager interno si existe
            if (isset($timerService) && $roundManager->getTurnManager()) {
                $roundManager->getTurnManager()->setTimerService($timerService);
            }

            $gameState = array_merge($gameState, $roundManager->toArray());
            Log::debug("Round system initialized with phases", [
                'total_rounds' => $totalRounds,
                'has_turn_manager' => $roundManager->getTurnManager() !== null
            ]);
        }

        // ========================================================================
        // MÓDULO: Scoring System
        // ========================================================================
        if ($modules['scoring_system']['enabled'] ?? false) {
            $scoringConfig = $modules['scoring_system'];
            $trackHistory = $scoringConfig['track_history'] ?? true;
            $allowNegative = $scoringConfig['allow_negative_scores'] ?? false;

            // El calculator debe ser proporcionado por el juego específico
            $calculator = $moduleOverrides['scoring_system']['calculator'] ?? null;

            if (!$calculator) {
                throw new \RuntimeException("Scoring calculator must be provided in moduleOverrides");
            }

            $scoreManager = new ScoreManager(
                playerIds: $playerIds,
                calculator: $calculator,
                trackHistory: $trackHistory
            );

            $gameState = array_merge($gameState, $scoreManager->toArray());
            Log::debug("Scoring system initialized", [
                'track_history' => $trackHistory,
                'allow_negative' => $allowNegative
            ]);
        }

        // ========================================================================
        // Guardar TimerService al final (después de conectarlo a otros módulos)
        // ========================================================================
        if (isset($timerService)) {
            $gameState = array_merge($gameState, $timerService->toArray());
        }

        // ========================================================================
        // MÓDULO: Teams System
        // ========================================================================
        if ($modules['teams_system']['enabled'] ?? false) {
            // Los equipos se crean en el lobby antes de iniciar
            // Aquí solo registramos que está enabled
            Log::debug("Teams system enabled", ['config' => $modules['teams_system']]);
        }

        // Guardar módulos inicializados
        $match->game_state = array_merge($match->game_state ?? [], $gameState);
        $match->save();

        Log::info("Modules initialized successfully", [
            'match_id' => $match->id,
            'game' => $gameSlug
        ]);
    }

    /**
     * Resetear módulos según la configuración del juego.
     *
     * Lee el config.json del juego para ver qué módulos están habilitados
     * y los resetea automáticamente según su configuración.
     *
     * @param GameMatch $match
     * @param array $overrides Parámetros específicos para override (ej: ['round_system' => ['current_round' => 5]])
     * @return void
     */
    protected function resetModules(GameMatch $match, array $overrides = []): void
    {
        $gameState = $match->game_state;
        $savedConfig = $gameState['_config'] ?? [];

        if (empty($savedConfig)) {
            throw new \RuntimeException("No game configuration found. Call initialize() first.");
        }

        // Cargar config.json del juego para saber qué módulos están enabled
        $gameSlug = $match->room->game->slug;
        $configPath = base_path("games/{$gameSlug}/config.json");

        if (!file_exists($configPath)) {
            throw new \RuntimeException("Game config.json not found for: {$gameSlug}");
        }

        $gameConfig = json_decode(file_get_contents($configPath), true);
        $modules = $gameConfig['modules'] ?? [];

        Log::info("Resetting modules for game", [
            'match_id' => $match->id,
            'game' => $gameSlug,
            'enabled_modules' => array_keys(array_filter($modules, fn($m) => $m['enabled'] ?? false))
        ]);

        // Obtener IDs de jugadores
        $playerIds = $match->players->pluck('id')->toArray();

        // ========================================================================
        // RESETEAR TIMER SYSTEM (crear PRIMERO para que otros módulos lo usen)
        // ========================================================================
        if (($modules['timer_system']['enabled'] ?? false)) {
            // Limpiar todos los timers
            $timerService = new TimerService();
            Log::debug("Timer system reset", ['timers_cleared' => true]);
        }

        // ========================================================================
        // RESETEAR ROUND SYSTEM
        // ========================================================================
        if (($modules['round_system']['enabled'] ?? false) && isset($gameState['round_system'])) {
            $roundManager = RoundManager::fromArray($gameState);

            // Reconectar TimerService al TurnManager si existe
            if (isset($timerService) && $roundManager->getTurnManager()) {
                $roundManager->getTurnManager()->setTimerService($timerService);
            }

            // Aplicar override si existe
            if (isset($overrides['round_system']['current_round'])) {
                $roundManager->setCurrentRound($overrides['round_system']['current_round']);
            } else {
                $roundManager->reset(); // Vuelve a ronda 1
            }

            $gameState = array_merge($gameState, $roundManager->toArray());
            Log::debug("Round system reset", ['current_round' => $roundManager->getCurrentRound()]);
        }

        // ========================================================================
        // RESETEAR SCORING SYSTEM
        // ========================================================================
        if (($modules['scoring_system']['enabled'] ?? false) && isset($gameState['scoring_system'])) {
            // Aplicar overrides si existen
            if (isset($overrides['scoring_system']['scores'])) {
                $gameState['scoring_system']['scores'] = $overrides['scoring_system']['scores'];
            } else {
                // Resetear todos los scores a 0
                foreach ($playerIds as $playerId) {
                    $gameState['scoring_system']['scores'][$playerId] = 0;
                }
            }

            // Limpiar historial si está habilitado
            if ($modules['scoring_system']['track_history'] ?? false) {
                $gameState['scoring_system']['score_history'] = [];
            }

            Log::debug("Scoring system reset", ['scores' => $gameState['scoring_system']['scores']]);
        }

        // ========================================================================
        // RESETEAR TURN SYSTEM (si está solo, sin RoundManager)
        // ========================================================================
        if (($modules['turn_system']['enabled'] ?? false) && isset($gameState['turn_system']) && !isset($roundManager)) {
            $turnManager = \App\Services\Modules\TurnSystem\TurnManager::fromArray($gameState['turn_system']);

            // Reconectar TimerService si existe
            if (isset($timerService)) {
                $turnManager->setTimerService($timerService);
            }

            // Aplicar override si existe
            if (isset($overrides['turn_system']['current_turn_index'])) {
                $turnManager->setCurrentTurnIndex($overrides['turn_system']['current_turn_index']);
            } else {
                $turnManager->reset(); // Vuelve al primer jugador
            }

            $gameState = array_merge($gameState, ['turn_system' => $turnManager->toArray()]);
            Log::debug("Turn system reset", [
                'current_turn_index' => $turnManager->getCurrentTurnIndex(),
                'mode' => $turnManager->getMode()
            ]);
        }

        // ========================================================================
        // Guardar TimerService al final (después de usarlo)
        // ========================================================================
        if (isset($timerService)) {
            $gameState = array_merge($gameState, $timerService->toArray());
        }

        // ========================================================================
        // RESETEAR TEAMS SYSTEM (si está enabled)
        // ========================================================================
        if (($modules['teams_system']['enabled'] ?? false) && isset($gameState['teams_system'])) {
            // Los equipos ya están formados, solo resetear sus scores si es necesario
            // (esto podría ser más complejo dependiendo del juego)
            Log::debug("Teams system detected", ['enabled' => true]);
        }

        // ========================================================================
        // RESETEAR ROLES SYSTEM (si existe)
        // ========================================================================
        if (isset($gameState['roles_system'])) {
            // Los roles se resetean en el método específico del juego
            // porque dependen de la lógica del juego
            Log::debug("Roles system detected", ['will_reset_in_game_logic' => true]);
        }

        // Guardar estado reseteado
        $match->game_state = $gameState;
        $match->save();

        Log::info("Modules reset complete", [
            'match_id' => $match->id,
            'game' => $gameSlug
        ]);
    }

    // ========================================================================
    // HELPERS: Generales
    // ========================================================================

    /**
     * Verificar si un módulo está habilitado en el juego.
     *
     * @param GameMatch $match
     * @param string $moduleName Nombre del módulo (ej: 'timer_system', 'scoring_system')
     * @return bool
     */
    protected function isModuleEnabled(GameMatch $match, string $moduleName): bool
    {
        return isset($match->game_state[$moduleName]) && !empty($match->game_state[$moduleName]);
    }

    // ========================================================================
    // HELPERS: RoundManager
    // ========================================================================

    /**
     * Obtener RoundManager del game_state.
     *
     * @param GameMatch $match
     * @return RoundManager
     */
    protected function getRoundManager(GameMatch $match): RoundManager
    {
        return RoundManager::fromArray($match->game_state);
    }

    /**
     * Guardar RoundManager de vuelta al game_state.
     *
     * @param GameMatch $match
     * @param RoundManager $roundManager
     * @return void
     */
    protected function saveRoundManager(GameMatch $match, RoundManager $roundManager): void
    {
        $match->game_state = array_merge(
            $match->game_state,
            $roundManager->toArray()
        );
        $match->save();
    }

    /**
     * Verificar si el juego ha terminado.
     *
     * @param GameMatch $match
     * @return bool
     */
    protected function isGameComplete(GameMatch $match): bool
    {
        return $this->getRoundManager($match)->isGameComplete();
    }

    /**
     * Verificar si jugador está eliminado temporalmente.
     *
     * @param GameMatch $match
     * @param int $playerId
     * @return bool
     */
    protected function isPlayerTemporarilyEliminated(GameMatch $match, int $playerId): bool
    {
        return $this->getRoundManager($match)->isTemporarilyEliminated($playerId);
    }

    /**
     * Obtener jugadores activos (no eliminados permanentemente).
     *
     * @param GameMatch $match
     * @return array
     */
    protected function getActivePlayers(GameMatch $match): array
    {
        return $this->getRoundManager($match)->getActivePlayers();
    }

    /**
     * Obtener orden de turnos.
     *
     * @param GameMatch $match
     * @return array
     */
    protected function getTurnOrder(GameMatch $match): array
    {
        return $this->getRoundManager($match)->getTurnOrder();
    }

    /**
     * Obtener ID del jugador actual (turno activo).
     *
     * @param GameMatch $match
     * @return int|null
     */
    protected function getCurrentPlayer(GameMatch $match): ?int
    {
        return $this->getRoundManager($match)->getCurrentPlayer();
    }

    // ========================================================================
    // HELPERS: ScoreManager
    // ========================================================================

    /**
     * Obtener ScoreManager del game_state.
     *
     * @param GameMatch $match
     * @param ScoreCalculatorInterface|null $calculator
     * @return ScoreManager
     */
    protected function getScoreManager(GameMatch $match, ?ScoreCalculatorInterface $calculator = null): ScoreManager
    {
        $gameState = $match->game_state;

        // Obtener player IDs desde el match directamente (no desde scores, que pueden estar vacíos)
        $playerIds = $match->players->pluck('id')->toArray();

        // Si no se pasó calculator, usar SimpleScoreCalculator por defecto
        if ($calculator === null) {
            $calculator = new \App\Services\Modules\ScoringSystem\SimpleScoreCalculator();
        }

        return ScoreManager::fromArray(
            playerIds: $playerIds,
            data: $gameState,
            calculator: $calculator
        );
    }

    /**
     * Guardar ScoreManager de vuelta al game_state.
     *
     * @param GameMatch $match
     * @param ScoreManager $scoreManager
     * @return void
     */
    protected function saveScoreManager(GameMatch $match, ScoreManager $scoreManager): void
    {
        $match->game_state = array_merge(
            $match->game_state,
            $scoreManager->toArray()
        );
        $match->save();
    }

    /**
     * Otorgar puntos a un jugador.
     *
     * @param GameMatch $match
     * @param int $playerId
     * @param string $reason
     * @param array $context
     * @param ScoreCalculatorInterface|null $calculator
     * @return void
     */
    protected function awardPoints(
        GameMatch $match,
        int $playerId,
        string $reason,
        array $context = [],
        ?ScoreCalculatorInterface $calculator = null
    ): void {
        $scoreManager = $this->getScoreManager($match, $calculator);
        $scoreManager->awardPoints($playerId, $reason, $context);
        $this->saveScoreManager($match, $scoreManager);
    }

    /**
     * Obtener ranking actual.
     *
     * @param GameMatch $match
     * @return array
     */
    protected function getRanking(GameMatch $match): array
    {
        return $this->getScoreManager($match)->getRanking();
    }

    /**
     * Obtener scores con backward compatibility.
     *
     * @param array $gameState
     * @return array
     */
    protected function getScores(array $gameState): array
    {
        return $gameState['scoring_system']['scores']
            ?? $gameState['scores']
            ?? [];
    }

    /**
     * Agregar puntos a un jugador (helper simplificado).
     *
     * Este método es un helper conveniente para agregar puntos directamente.
     * Internamente usa ScoreManager.
     *
     * @param GameMatch $match
     * @param int $playerId
     * @param int $points
     * @param string $reason
     * @return void
     */
    protected function addScore(GameMatch $match, int $playerId, int $points, string $reason = 'action'): void
    {
        $scoreManager = $this->getScoreManager($match);
        $scoreManager->addScore($playerId, $points);
        $this->saveScoreManager($match, $scoreManager);

        Log::info("[{$this->getGameSlug()}] Score added", [
            'player_id' => $playerId,
            'points' => $points,
            'reason' => $reason,
            'new_score' => $scoreManager->getScore($playerId)
        ]);
    }

    // ========================================================================
    // HELPERS: Player Cache (elimina queries durante el juego)
    // ========================================================================

    /**
     * Cachear datos de jugadores en game_state durante initialize().
     *
     * Esto se llama UNA VEZ al inicializar el juego para guardar todos los
     * datos de los jugadores en _config['players'] y evitar queries durante el gameplay.
     *
     * @param GameMatch $match
     * @return void
     */
    protected function cachePlayersInState(GameMatch $match): void
    {
        $players = $match->players; // ← Query SOLO aquí (1 vez)

        $playersData = [];

        foreach ($players as $player) {
            $playersData[$player->id] = [
                'id' => $player->id,
                'name' => $player->name,
                'user_id' => $player->user_id,
                'avatar' => $player->avatar ?? null,
            ];
        }

        $gameState = $match->game_state;
        $gameState['_config']['players'] = $playersData;
        $gameState['_config']['total_players'] = count($playersData);
        $match->game_state = $gameState;
        $match->save();

        Log::info("[{$this->getGameSlug()}] Players cached in game_state", [
            'match_id' => $match->id,
            'player_count' => count($playersData)
        ]);
    }

    /**
     * Obtener datos del jugador desde game_state (SIN query).
     *
     * Los datos del jugador se guardan en _config['players'] al inicializar el juego.
     *
     * @param GameMatch $match
     * @param int $playerId
     * @return Player|null
     */
    protected function getPlayerFromState(GameMatch $match, int $playerId): ?Player
    {
        $playerData = $match->game_state['_config']['players'][$playerId] ?? null;

        if (!$playerData) {
            Log::warning("[{$this->getGameSlug()}] Player not found in cached state", [
                'player_id' => $playerId,
                'match_id' => $match->id
            ]);
            return null;
        }

        // Crear Player object desde datos en memoria (NO query!)
        $player = new Player();
        $player->id = $playerData['id'];
        $player->name = $playerData['name'];
        $player->user_id = $playerData['user_id'];
        $player->avatar = $playerData['avatar'] ?? null;
        $player->exists = true;

        return $player;
    }

    /**
     * Broadcast helper optimizado para enviar eventos a una sala.
     *
     * @param mixed $event Evento a broadcast
     * @param GameMatch $match
     * @return void
     */
    protected function broadcastToRoom($event, GameMatch $match): void
    {
        broadcast($event)->toOthers();

        Log::debug("[{$this->getGameSlug()}] Event broadcasted to room", [
            'event' => class_basename($event),
            'room_code' => $match->room->code ?? 'unknown'
        ]);
    }

    // ========================================================================
    // HELPERS: PlayerStateManager
    // ========================================================================

    /**
     * Obtener PlayerStateManager del game_state.
     *
     * @param GameMatch $match
     * @return \App\Services\Modules\PlayerStateSystem\PlayerStateManager
     */
    protected function getPlayerStateManager(GameMatch $match): \App\Services\Modules\PlayerStateSystem\PlayerStateManager
    {
        $playerState = \App\Services\Modules\PlayerStateSystem\PlayerStateManager::fromArray($match->game_state);
        
        // Si no tiene player_ids (estado antiguo o no inicializado), agregarlos ahora
        if ($playerState->getTotalPlayers() === 0) {
            $playerIds = $match->players->pluck('id')->toArray();
            
            foreach ($playerIds as $playerId) {
                $playerState->addPlayer($playerId);
            }
            
            Log::info("[{$this->getGameSlug()}] PlayerStateManager initialized with player IDs", [
                'match_id' => $match->id,
                'player_count' => count($playerIds)
            ]);
            
            // Guardar el estado actualizado
            $this->savePlayerStateManager($match, $playerState);
        }
        
        return $playerState;
    }

    /**
     * Guardar PlayerStateManager de vuelta al game_state.
     *
     * @param GameMatch $match
     * @param \App\Services\Modules\PlayerStateSystem\PlayerStateManager $playerState
     * @return void
     */
    protected function savePlayerStateManager(GameMatch $match, \App\Services\Modules\PlayerStateSystem\PlayerStateManager $playerState): void
    {
        $match->game_state = array_merge(
            $match->game_state,
            $playerState->toArray()
        );
        $match->save();
    }

    // ========================================================================
    // HELPERS: PlayerManager (Unified scores + state)
    // ========================================================================

    /**
     * Obtener PlayerManager del game_state.
     *
     * PlayerManager unifica ScoreManager + PlayerStateManager en un solo gestor.
     * Todos los juegos deberían migrar a usar PlayerManager en el futuro.
     *
     * @param GameMatch $match
     * @param object|null $scoreCalculator ScoreCalculator del juego (opcional)
     * @return \App\Services\Modules\PlayerSystem\PlayerManager
     */
    protected function getPlayerManager(GameMatch $match, ?object $scoreCalculator = null): \App\Services\Modules\PlayerSystem\PlayerManager
    {
        // Si ya existe en game_state, restaurar
        if (isset($match->game_state['player_system'])) {
            return \App\Services\Modules\PlayerSystem\PlayerManager::fromArray(
                $match->game_state,
                $scoreCalculator
            );
        }

        // Si no existe, crear nuevo con los jugadores del match
        $playerIds = $match->players->pluck('id')->toArray();

        $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager(
            $playerIds,
            $scoreCalculator,
            [
                'available_roles' => $match->game_state['_config']['modules']['roles_system']['roles'] ?? [],
                'allow_multiple_persistent_roles' => false,
                'track_score_history' => false,
            ]
        );

        // Guardar estado inicial
        $this->savePlayerManager($match, $playerManager);

        Log::info("[{$this->getGameSlug()}] PlayerManager initialized", [
            'match_id' => $match->id,
            'player_count' => count($playerIds)
        ]);

        return $playerManager;
    }

    /**
     * Guardar PlayerManager de vuelta al game_state.
     *
     * @param GameMatch $match
     * @param \App\Services\Modules\PlayerSystem\PlayerManager $playerManager
     * @return void
     */
    protected function savePlayerManager(GameMatch $match, \App\Services\Modules\PlayerSystem\PlayerManager $playerManager): void
    {
        $match->game_state = array_merge(
            $match->game_state,
            $playerManager->toArray()
        );
        $match->save();
    }

    // ========================================================================
    // HELPERS: TimerService
    // ========================================================================

    /**
     * Obtener TimerService del game_state.
     *
     * @param GameMatch $match
     * @return TimerService
     * @throws \RuntimeException Si el timer_system no está configurado
     */
    protected function getTimerService(GameMatch $match): TimerService
    {
        if (!isset($match->game_state['timer_system'])) {
            throw new \RuntimeException(
                "Timer system not configured for this game. " .
                "Enable 'timer_system' in game capabilities or check module requirements."
            );
        }

        return TimerService::fromArray($match->game_state);
    }

    /**
     * Guardar TimerService de vuelta al game_state.
     *
     * @param GameMatch $match
     * @param TimerService $timerService
     * @return void
     */
    protected function saveTimerService(GameMatch $match, TimerService $timerService): void
    {
        $match->game_state = array_merge(
            $match->game_state,
            $timerService->toArray()
        );
        $match->save();
    }

    /**
     * Crear un timer.
     *
     * @param GameMatch $match
     * @param string $name
     * @param int $seconds
     * @param bool $restart Si true, reinicia el timer si ya existe
     * @return void
     */
    /**
     * Crear timer con evento configurado.
     *
     * @param GameMatch $match
     * @param string $name Nombre del timer
     * @param int $seconds Duración en segundos
     * @param string|null $eventToEmit Clase del evento a emitir cuando expire
     * @param array $eventData Datos a pasar al evento
     * @param bool $restart Si true, reinicia el timer si ya existe
     * @return void
     */
    protected function createTimer(
        GameMatch $match,
        string $name,
        int $seconds,
        ?string $eventToEmit = null,
        array $eventData = [],
        bool $restart = false
    ): void {
        $timerService = $this->getTimerService($match);
        $timerService->startTimer($name, $seconds, $eventToEmit, $eventData, null, $restart);
        $this->saveTimerService($match, $timerService);
    }

    /**
     * Iniciar timer de ronda automáticamente si está configurado.
     *
     * @deprecated Este método está DEPRECADO. Usa RoundManager::startRoundTimer() en su lugar.
     *
     * RAZÓN: Los módulos deben gestionar sus propios timers y eventos.
     * RoundManager es responsable de los timers de ronda, no BaseGameEngine.
     *
     * Este método se mantiene por compatibilidad pero ya no se usa internamente.
     * handleNewRound() ahora delega a RoundManager::startRoundTimer().
     *
     * @param GameMatch $match
     * @param string $timerName Nombre del timer (default: 'round')
     * @return bool True si se inició timer, false si no está configurado
     */
    protected function startRoundTimer(GameMatch $match, string $timerName = 'round'): bool
    {
        $config = $this->getGameConfig();

        // Buscar duración del timer en configuración
        $duration = $config['modules']['timer_system']['round_duration'] ?? null;

        if ($duration === null || $duration <= 0) {
            return false;
        }

        Log::info("[{$this->getGameSlug()}] Starting round timer", [
            'match_id' => $match->id,
            'timer_name' => $timerName,
            'duration' => $duration
        ]);

        // Configurar timer para emitir RoundTimerExpiredEvent cuando expire
        $currentRound = $match->game_state['round_system']['current_round'] ?? 1;
        $this->createTimer(
            match: $match,
            name: $timerName,
            seconds: $duration,
            eventToEmit: \App\Events\Game\RoundTimerExpiredEvent::class,
            eventData: [$match, $currentRound, $timerName],
            restart: true
        );

        return true;
    }

    /**
     * Verificar si timer expiró.
     *
     * @param GameMatch $match
     * @param string $name
     * @return bool
     */
    protected function isTimerExpired(GameMatch $match, string $name): bool
    {
        return $this->getTimerService($match)->isExpired($name);
    }

    /**
     * Obtener tiempo restante de un timer.
     *
     * @param GameMatch $match
     * @param string $name
     * @return int
     */
    protected function getTimeRemaining(GameMatch $match, string $name): int
    {
        return $this->getTimerService($match)->getTimeRemaining($name);
    }

    /**
     * Obtener tiempo transcurrido de un timer (elapsed time).
     *
     * Útil para scoring basado en rapidez (bonificación por responder rápido).
     *
     * @param GameMatch $match
     * @param string $name
     * @return int Segundos transcurridos desde que inició el timer
     */
    protected function getElapsedTime(GameMatch $match, string $name): int
    {
        return $this->getTimerService($match)->getElapsedTime($name);
    }

    /**
     * Verificar timer y finalizar ronda si expiró.
     *
     * Este método verifica si el timer de ronda ha expirado y, si es así,
     * finaliza la ronda actual con resultados de timeout (todos los que no
     * respondieron obtienen 0 puntos).
     *
     * Se debe llamar después de procesar acciones de jugadores.
     *
     * @param GameMatch $match
     * @return bool True si se finalizó la ronda por timeout
     */
    public function checkTimerAndAutoAdvance(GameMatch $match): bool
    {
        $gameState = $match->game_state;

        // Verificar si hay un timer de ronda activo
        if (!isset($gameState['timer_system']['timers']['round'])) {
            return false;
        }

        // Verificar si el timer ha expirado
        try {
            $isExpired = $this->isTimerExpired($match, 'round');
        } catch (\Exception $e) {
            Log::debug("[{$this->getGameSlug()}] Timer check failed: {$e->getMessage()}");
            return false;
        }

        if (!$isExpired) {
            return false;
        }

        $currentRound = $gameState['round_system']['current_round'] ?? null;

        Log::info("[{$this->getGameSlug()}] Round timer expired", [
            'match_id' => $match->id,
            'current_round' => $currentRound
        ]);

        // Obtener TimerService y delegar emisión del evento
        $timerService = $this->getTimerService($match);
        $timerService->emitTimerExpiredEvent($match, 'round', $currentRound);

        // Delegar al juego específico para que decida qué hacer
        // Trivia: completará la ronda
        // Otro juego: puede hacer algo diferente (pasar turno, penalizar, etc.)
        $this->onRoundTimerExpired($match, 'round');

        return true;
    }

    // ========================================================================
    // TURN MANAGEMENT: Automatic Role Rotation
    // ========================================================================

    /**
     * Avanzar al siguiente turno con rotación automática de roles.
     *
     * Este método provee una implementación estándar para juegos secuenciales:
     * 1. Limpia eliminaciones temporales (si aplica)
     * 2. Avanza el turno usando RoundManager
     * 3. Rota roles automáticamente si shouldAutoRotateRoles() retorna true
     * 4. Reinicia timers si existen
     *
     * Los juegos pueden:
     * - Usar este método tal cual (llamando parent::nextTurn($match))
     * - Sobrescribirlo completamente para lógica custom
     * - Sobrescribir shouldAutoRotateRoles() para control fino
     *
     * @param GameMatch $match
     * @return array Información del nuevo turno ['player_id', 'turn_index', 'round']
     */
    protected function nextTurn(GameMatch $match): array
    {
        $gameState = $match->game_state;
        $roundManager = $this->getRoundManager($match);

        // Paso 1: Limpiar eliminaciones temporales (si el juego lo necesita)
        if ($this->shouldClearTemporaryEliminations()) {
            $roundManager->clearTemporaryEliminations();
        }

        // Paso 2: Avanzar turno
        // En round-per-turn mode, cada turno avanza la ronda automáticamente
        // Esto implica cambios coordinados en: ronda, turno, player Y rol
        $config = $this->getGameConfig();
        $roundPerTurn = $config['modules']['turn_system']['round_per_turn'] ?? false;

        if ($roundPerTurn) {
            $turnInfo = $roundManager->nextTurnWithRoundAdvance();
        } else {
            $turnInfo = $roundManager->nextTurn();
        }

        // Paso 3: Rotar roles automáticamente (si aplica)
        $shouldRotate = $this->shouldAutoRotateRoles($match);
        Log::info("🔍 Checking if should rotate roles", [
            'match_id' => $match->id,
            'should_rotate' => $shouldRotate,
            'turn_mode' => $roundManager->getTurnManager()->getMode()
        ]);

        // Rotación de roles eliminada - usar PlayerStateManager directamente en el juego

        // Paso 4: Reiniciar timers (si existen)
        $this->restartTurnTimers($match);

        // Guardar cambios
        $this->saveRoundManager($match, $roundManager);

        return $turnInfo;
    }

    /**
     * Determinar si se deben limpiar eliminaciones temporales al avanzar turno.
     *
     * Por defecto false. Los juegos como Pictionary sobrescriben esto.
     *
     * @return bool
     */
    protected function shouldClearTemporaryEliminations(): bool
    {
        return false;
    }

    /**
     * Reiniciar timers del turno (si existen).
     *
     * @param GameMatch $match
     * @return void
     */
    protected function restartTurnTimers(GameMatch $match): void
    {
        $config = $this->getGameConfig();

        // Verificar si tiene Timer System habilitado
        if (isset($config['modules']['timer']['enabled']) && $config['modules']['timer']['enabled']) {
            $timerService = $this->getTimerService($match);

            // Reiniciar timer del turno si existe
            if ($timerService->hasTimer('turn_timer')) {
                $timerService->restartTimer('turn_timer');
                $this->saveTimerService($match, $timerService);
            }
        }
    }

    /**
     * Obtener configuración del juego desde config.json.
     *
     * Implementación por defecto que carga automáticamente config.json
     * basándose en el slug del juego. Los Engines pueden sobrescribir
     * este método si necesitan lógica adicional.
     *
     * Caching: Usa caché estática para evitar lecturas múltiples del archivo.
     *
     * @return array
     */
    protected function getGameConfig(): array
    {
        static $configs = [];
        $slug = $this->getGameSlug();

        if (!isset($configs[$slug])) {
            $configPath = base_path("games/{$slug}/config.json");
            $configs[$slug] = file_exists($configPath)
                ? json_decode(file_get_contents($configPath), true)
                : [];
        }

        return $configs[$slug];
    }

    /**
     * Normalizar configuración de fases para PhaseManager.
     *
     * LÓGICA:
     * - Si hay timing.{phase_name} configurados → juego multi-fase (ej: Mentiroso)
     * - Si NO hay → juego single-fase, usar timer_system.round_duration o modules.turn_system.time_limit
     *
     * @return array Array de fases [{name, duration}, ...]
     */
    protected function getPhaseConfig(): array
    {
        $config = $this->getGameConfig();
        $timing = $config['timing'] ?? [];
        $timerSystem = $config['modules']['timer_system'] ?? [];
        $turnSystem = $config['modules']['turn_system'] ?? [];

        // 1. MULTI-FASE: Si hay configuración explícita de fases en timing
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

    // ========================================================================
    // HELPERS: Game State (Backward Compatibility)
    // ========================================================================

    /**
     * Obtener total de rondas con backward compatibility.
     *
     * @param array $gameState
     * @return int
     */
    protected function getTotalRounds(array $gameState): int
    {
        return $gameState['round_system']['total_rounds']
            ?? $gameState['total_rounds']
            ?? 0;
    }

    /**
     * Obtener ronda actual con backward compatibility.
     *
     * @param array $gameState
     * @return int
     */
    protected function getCurrentRound(array $gameState): int
    {
        return $gameState['round_system']['current_round']
            ?? $gameState['current_round']
            ?? 1;
    }

    /**
     * Obtener jugadores eliminados temporalmente con backward compatibility.
     *
     * @param array $gameState
     * @return array
     */
    protected function getTemporarilyEliminated(array $gameState): array
    {
        return $gameState['round_system']['temporarily_eliminated']
            ?? $gameState['temporarily_eliminated']
            ?? [];
    }

    // ========================================================================
    // UTILIDADES
    // ========================================================================

    /**
     * Obtener el slug del juego (para logging).
     *
     * @return string
     */
    protected function getGameSlug(): string
    {
        $className = class_basename($this);
        return strtolower(str_replace('Engine', '', $className));
    }

    // ========================================================================
    // TURN TIMEOUT HANDLING
    // ========================================================================

    /**
     * Manejar timeout del turno cuando el tiempo expira.
     *
     * Este método es llamado por GameController cuando el frontend notifica
     * que el timer del turno llegó a 0.
     *
     * Por defecto, finaliza la ronda actual (lo cual asigna 0 puntos a quien no respondió).
     * Los juegos pueden sobrescribir este método si necesitan comportamiento custom.
     *
     * @param GameMatch $match
     * @return void
     */
    public function onTurnTimeout(GameMatch $match): void
    {
        Log::info("[{$this->getGameSlug()}] Turn timeout - ending current round", [
            'match_id' => $match->id,
            'current_phase' => $match->game_state['phase'] ?? 'unknown'
        ]);

        // Por defecto, finalizar la ronda actual
        // Esto asignará 0 puntos a jugadores que no respondieron
        $this->endCurrentRound($match);
    }

    // ========================================================================
    // MÉTODOS OPCIONALES: Pueden ser sobrescritos si es necesario
    // ========================================================================

    /**
     * Manejar desconexión de jugador.
     *
     * Por defecto, no hace nada. Los juegos pueden sobrescribir si necesitan.
     *
     * @param GameMatch $match
     * @param Player $player
     * @return void
     */
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void
    {
        Log::info("[{$this->getGameSlug()}] Player disconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);

        // Por defecto no hace nada
        // Los juegos pueden sobrescribir este método si necesitan lógica especial
    }

    /**
     * Manejar reconexión de jugador.
     *
     * Por defecto, no hace nada. Los juegos pueden sobrescribir si necesitan.
     *
     * @param GameMatch $match
     * @param Player $player
     * @return void
     */
    public function handlePlayerReconnect(GameMatch $match, Player $player): void
    {
        Log::info("[{$this->getGameSlug()}] Player reconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);

        // Por defecto no hace nada
        // Los juegos pueden sobrescribir este método si necesitan lógica especial
    }

    /**
     * Verificar condición de victoria.
     *
     * Por defecto retorna null. Los juegos pueden sobrescribir.
     *
     * @param GameMatch $match
     * @return Player|null
     */
    public function checkWinCondition(GameMatch $match): ?Player
    {
        // Por defecto no hay ganador único
        // Los juegos pueden sobrescribir si tienen condición de victoria específica
        return null;
    }

    /**
     * Obtener estado del juego para un jugador.
     *
     * Por defecto retorna el game_state completo.
     * Los juegos pueden sobrescribir para filtrar información secreta.
     *
     * @param GameMatch $match
     * @param Player $player
     * @return array
     */
    public function getGameStateForPlayer(GameMatch $match, Player $player): array
    {
        // Por defecto retorna todo el game_state
        // Los juegos pueden sobrescribir para filtrar información
        return $match->game_state ?? [];
    }

    // ========================================================================
    // GAME FINALIZATION - Template Method Pattern
    // ========================================================================

    /**
     * Finalizar el juego (Template Method).
     *
     * Este método implementa el Template Method Pattern:
     * - Define el flujo general de finalización (genérico)
     * - Delega el cálculo de scores a getFinalScores() (específico de cada juego)
     *
     * Flujo de finalización:
     * 1. Obtener scores finales (delegado a cada juego)
     * 2. Crear ranking ordenado por puntos
     * 3. Determinar ganador (primer lugar en ranking)
     * 4. Actualizar game_state a 'finished'
     * 5. Emitir GameEndedEvent
     *
     * @param GameMatch $match
     * @return array Resultado con ranking y scores
     */
    public function finalize(GameMatch $match): array
    {
        Log::info("[{$this->getGameSlug()}] Finalizing game", ['match_id' => $match->id]);

        // 1. Obtener scores finales (delegado a cada juego)
        $scores = $this->getFinalScores($match);

        // 2. Crear ranking ordenado por puntos
        arsort($scores);
        $ranking = [];
        $position = 1;

        foreach ($scores as $playerId => $score) {
            $ranking[] = [
                'position' => $position++,
                'player_id' => $playerId,
                'score' => $score,
            ];
        }

        // 3. Determinar ganador (el primero en ranking)
        $winner = !empty($ranking) ? $ranking[0]['player_id'] : null;

        // 4. Marcar partida como terminada
        $match->game_state = array_merge($match->game_state, [
            'phase' => 'finished',
            'finished_at' => now()->toDateTimeString(),
            'final_scores' => $scores,
            'ranking' => $ranking,
            'winner' => $winner,
        ]);

        $match->save();

        // 5. Emitir evento de juego terminado
        event(new \App\Events\Game\GameEndedEvent(
            match: $match,
            winner: $winner,
            ranking: $ranking,
            scores: $scores
        ));

        Log::info("[{$this->getGameSlug()}] Game finalized", [
            'match_id' => $match->id,
            'winner' => $winner,
            'total_players' => count($ranking)
        ]);

        return [
            'ranking' => $ranking,
            'scores' => $scores,
            'winner' => $winner,
        ];
    }

    /**
     * Obtener scores finales.
     *
     * Implementación por defecto que funciona para todos los juegos que usan PlayerManager.
     * Los juegos pueden sobrescribir este método si necesitan lógica de scoring personalizada.
     *
     * @param GameMatch $match
     * @return array Array asociativo [player_id => score]
     */
    protected function getFinalScores(GameMatch $match): array
    {
        Log::info("[{$this->getGameSlug()}] Calculating final scores", ['match_id' => $match->id]);

        // Obtener scoreCalculator si está disponible como propiedad del juego
        $calculator = $this->scoreCalculator ?? null;

        // Usar PlayerManager para obtener los scores
        $playerManager = $this->getPlayerManager($match, $calculator);

        return $playerManager->getScores();
    }

}

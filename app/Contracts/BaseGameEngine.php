<?php

namespace App\Contracts;

use App\Contracts\Strategies\EndRoundStrategy;
use App\Contracts\Strategies\FreeEndStrategy;
use App\Contracts\Strategies\SequentialEndStrategy;
use App\Contracts\Strategies\SimultaneousEndStrategy;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Modules\RolesSystem\RoleManager;
use App\Services\Modules\RoundSystem\RoundManager;
use App\Services\Modules\ScoringSystem\Contracts\ScoreCalculator;
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
     * Iniciar una nueva ronda del juego.
     *
     * @param GameMatch $match
     * @return void
     */
    abstract protected function startNewRound(GameMatch $match): void;

    /**
     * Finalizar la ronda actual y calcular resultados.
     *
     * @param GameMatch $match
     * @return void
     */
    abstract protected function endCurrentRound(GameMatch $match): void;

    /**
     * Obtener todos los resultados de jugadores en la ronda actual.
     *
     * Formato: [player_id => ['success' => bool, 'data' => mixed], ...]
     *
     * @param GameMatch $match
     * @return array
     */
    abstract protected function getAllPlayerResults(GameMatch $match): array;

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

            // Programar siguiente ronda vía RoundManager
            $matchId = $match->id;
            $delaySeconds = $roundStatus['delay_seconds'] ?? 5;

            // Recargar roundManager después de endCurrentRound (puede haber cambiado el state)
            $roundManager = $this->getRoundManager($match);

            $roundManager->scheduleNextRound(function () use ($matchId) {
                $match = GameMatch::find($matchId);
                if ($match && !$this->isGameComplete($match)) {
                    $this->startNewRound($match);
                }
            }, delaySeconds: $delaySeconds);
        }

        // 6. Retornar resultado con información adicional
        return array_merge($actionResult, [
            'round_status' => $roundStatus,
            'turn_mode' => $turnMode,
        ]);
    }

    /**
     * Avanzar a la siguiente fase del juego.
     *
     * Método genérico que delega a startNewRound().
     *
     * @param GameMatch $match
     * @return void
     */
    public function advancePhase(GameMatch $match): void
    {
        $this->startNewRound($match);
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
     * @param ScoreCalculator|null $calculator
     * @return ScoreManager
     */
    protected function getScoreManager(GameMatch $match, ?ScoreCalculator $calculator = null): ScoreManager
    {
        $gameState = $match->game_state;
        $playerIds = array_keys($this->getScores($gameState));

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
     * @param ScoreCalculator|null $calculator
     * @return void
     */
    protected function awardPoints(
        GameMatch $match,
        int $playerId,
        string $reason,
        array $context = [],
        ?ScoreCalculator $calculator = null
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

    // ========================================================================
    // HELPERS: RoleManager
    // ========================================================================

    /**
     * Obtener RoleManager del game_state.
     *
     * @param GameMatch $match
     * @return RoleManager
     */
    protected function getRoleManager(GameMatch $match): RoleManager
    {
        return RoleManager::fromArray($match->game_state);
    }

    /**
     * Guardar RoleManager de vuelta al game_state.
     *
     * @param GameMatch $match
     * @param RoleManager $roleManager
     * @return void
     */
    protected function saveRoleManager(GameMatch $match, RoleManager $roleManager): void
    {
        $match->game_state = array_merge(
            $match->game_state,
            $roleManager->toArray()
        );
        $match->save();
    }

    /**
     * Verificar si jugador tiene un rol.
     *
     * @param GameMatch $match
     * @param int $playerId
     * @param string $role
     * @return bool
     */
    protected function playerHasRole(GameMatch $match, int $playerId, string $role): bool
    {
        return $this->getRoleManager($match)->hasRole($playerId, $role);
    }

    /**
     * Asignar rol a jugador.
     *
     * @param GameMatch $match
     * @param int $playerId
     * @param string $role
     * @return void
     */
    protected function assignRole(GameMatch $match, int $playerId, string $role): void
    {
        $roleManager = $this->getRoleManager($match);
        $roleManager->assignRole($playerId, $role);
        $this->saveRoleManager($match, $roleManager);
    }

    /**
     * Rotar rol al siguiente jugador.
     *
     * @param GameMatch $match
     * @param string $role
     * @param array $turnOrder
     * @return int ID del nuevo jugador con el rol
     */
    protected function rotateRole(GameMatch $match, string $role, array $turnOrder): int
    {
        $roleManager = $this->getRoleManager($match);
        $newPlayerId = $roleManager->rotateRole($role, $turnOrder);
        $this->saveRoleManager($match, $roleManager);
        return $newPlayerId;
    }

    /**
     * Obtener jugadores con un rol específico.
     *
     * @param GameMatch $match
     * @param string $role
     * @return array
     */
    protected function getPlayersWithRole(GameMatch $match, string $role): array
    {
        return $this->getRoleManager($match)->getPlayersWithRole($role);
    }

    // ========================================================================
    // HELPERS: TimerService
    // ========================================================================

    /**
     * Obtener TimerService del game_state.
     *
     * @param GameMatch $match
     * @return TimerService
     */
    protected function getTimerService(GameMatch $match): TimerService
    {
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
     * @return void
     */
    protected function createTimer(GameMatch $match, string $name, int $seconds): void
    {
        $timerService = $this->getTimerService($match);
        $timerService->createTimer($name, $seconds);
        $this->saveTimerService($match, $timerService);
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
        $turnInfo = $roundManager->nextTurn();

        // Paso 3: Rotar roles automáticamente (si aplica)
        if ($this->shouldAutoRotateRoles($match)) {
            $this->autoRotateRoles($match, $roundManager);
        }

        // Paso 4: Reiniciar timers (si existen)
        $this->restartTurnTimers($match);

        // Guardar cambios
        $this->saveRoundManager($match, $roundManager);

        return $turnInfo;
    }

    /**
     * Determinar si se deben rotar roles automáticamente.
     *
     * Por defecto, rota en modos secuenciales (sequential, shuffle).
     * Los juegos pueden sobrescribir esto para control custom.
     *
     * @param GameMatch $match
     * @return bool
     */
    protected function shouldAutoRotateRoles(GameMatch $match): bool
    {
        $mode = $this->getRoundManager($match)->getTurnManager()->getMode();
        return in_array($mode, ['sequential', 'shuffle']);
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
     * Rotar roles automáticamente basándose en la configuración del juego.
     *
     * Lee los roles del config.json y los rota según el turn_order.
     * Asume que cada rol es exclusivo (un jugador solo puede tener un rol activo).
     *
     * @param GameMatch $match
     * @param RoundManager $roundManager
     * @return void
     */
    protected function autoRotateRoles(GameMatch $match, RoundManager $roundManager): void
    {
        $rotatableRoles = $this->getRotatableRoles();

        if (empty($rotatableRoles)) {
            return; // Sin roles que rotar
        }

        $roleManager = $this->getRoleManager($match);
        $turnOrder = $roundManager->getTurnOrder();

        // Rotar cada rol
        foreach ($rotatableRoles as $roleName) {
            $newRolePlayerId = $this->rotateRole($match, $roleName, $turnOrder);

            // Si hay roles complementarios (ej: drawer/guesser en Pictionary)
            $complementaryRole = $this->getComplementaryRole($roleName);

            if ($complementaryRole) {
                // Asignar rol complementario a todos excepto el que tiene el rol principal
                foreach ($turnOrder as $playerId) {
                    if ($playerId !== $newRolePlayerId) {
                        if (!$roleManager->hasRole($playerId, $complementaryRole)) {
                            $roleManager->assignRole($playerId, $complementaryRole);
                        }
                    } else {
                        // Remover rol complementario del jugador con rol principal
                        $roleManager->removeRole($playerId, $complementaryRole);
                    }
                }
            }
        }

        $this->saveRoleManager($match, $roleManager);
    }

    /**
     * Obtener lista de roles que deben rotarse automáticamente.
     *
     * Por defecto, lee del config.json si existe configuración de roles.
     * Los juegos pueden sobrescribir para control custom.
     *
     * @return array Lista de nombres de roles a rotar
     */
    protected function getRotatableRoles(): array
    {
        $config = $this->getGameConfig();

        // Buscar roles en la configuración de módulos
        if (isset($config['modules']['roles']['enabled']) && $config['modules']['roles']['enabled']) {
            $roles = $config['modules']['roles']['roles'] ?? [];

            // Filtrar roles que no son complementarios (ej: 'drawer' sí, 'guesser' no)
            return array_filter($roles, function($role) {
                return !$this->isComplementaryRole($role);
            });
        }

        return [];
    }

    /**
     * Determinar si un rol es complementario (no debe rotarse directamente).
     *
     * Por defecto, retorna false. Los juegos sobrescriben si tienen roles complementarios.
     *
     * @param string $role
     * @return bool
     */
    protected function isComplementaryRole(string $role): bool
    {
        return false;
    }

    /**
     * Obtener rol complementario de un rol principal.
     *
     * Por ejemplo: 'drawer' -> 'guesser' en Pictionary
     *
     * @param string $role
     * @return string|null
     */
    protected function getComplementaryRole(string $role): ?string
    {
        return null;
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
     * Obtener configuración del juego.
     *
     * Los Engines deben implementar este método si usan rotación automática.
     *
     * @return array
     */
    protected function getGameConfig(): array
    {
        return [];
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
}

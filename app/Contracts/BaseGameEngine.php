<?php

namespace App\Contracts;

use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Modules\RoundSystem\RoundManager;
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
 *    - calculateRoundResults()  : ¿Cómo se calculan los resultados?
 *
 * 2. COORDINACIÓN CON MÓDULOS (métodos concretos - ya implementados aquí)
 *    - shouldEndRound()         : RoundManager decide cuándo terminar
 *    - scheduleNextRound()      : RoundManager programa el avance
 *    - updateScores()           : ScoreManager gestiona puntuación
 *    - checkGameCompletion()    : RoundManager verifica si terminó el juego
 *
 * DESACOPLAMIENTO:
 * ================
 * - El Engine NO decide cuándo terminar rondas (lo hace RoundManager)
 * - El Engine NO gestiona turnos directamente (lo hace TurnManager vía RoundManager)
 * - El Engine NO programa delays manualmente (lo hace RoundManager)
 * - El Engine SOLO define qué pasa en cada ronda (lógica del juego)
 *
 * @see docs/GAMES_CONVENTION.md
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
    // MÉTODOS CONCRETOS: Coordinación con módulos (ya implementados)
    // ========================================================================

    /**
     * Procesar una acción de un jugador.
     *
     * Este método coordina entre la lógica del juego y los módulos.
     * Soporta diferentes modos de juego automáticamente.
     *
     * FLUJO:
     * 1. Procesar acción específica del juego
     * 2. Detectar modo de juego (simultáneo/secuencial)
     * 3. Consultar a RoundManager si debe terminar la ronda/turno
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
        // Pasar el tipo de acción en los datos para que el juego sepa qué hacer
        $data['action'] = $action;
        $actionResult = $this->processRoundAction($match, $player, $data);

        // 2. Obtener RoundManager y detectar modo
        $gameState = $match->game_state;
        $roundManager = RoundManager::fromArray($gameState);
        $turnManager = $roundManager->getTurnManager();
        $turnMode = $turnManager->getMode();

        // 3. Verificar si debe terminar según el modo
        $roundStatus = ['should_end' => false];

        if ($turnMode === 'simultaneous') {
            // Modo simultáneo: Verificar si todos respondieron o alguien ganó
            $playerResults = $this->getAllPlayerResults($match);
            $roundStatus = $roundManager->shouldEndSimultaneousRound($playerResults);
        } elseif ($turnMode === 'sequential') {
            // Modo secuencial: El juego decide cuándo terminar
            // Verificar si el juego dice que debe terminar el turno
            $shouldEnd = $actionResult['should_end_turn'] ?? false;
            $roundStatus = [
                'should_end' => $shouldEnd,
                'reason' => $shouldEnd ? 'turn_completed' : 'turn_ongoing',
                'delay_seconds' => $actionResult['delay_seconds'] ?? 3,
            ];
        }

        // 4. Actuar según decisión
        if ($roundStatus['should_end']) {
            Log::info("[{$this->getGameSlug()}] Round/Turn ending", [
                'match_id' => $match->id,
                'mode' => $turnMode,
                'reason' => $roundStatus['reason'] ?? 'game_decided'
            ]);

            // Finalizar ronda/turno actual
            $this->endCurrentRound($match);

            // Programar siguiente ronda vía RoundManager
            $matchId = $match->id;
            $delaySeconds = $roundStatus['delay_seconds'] ?? 5;

            $roundManager->scheduleNextRound(function () use ($matchId) {
                $match = GameMatch::find($matchId);
                if ($match && !$this->isGameComplete($match)) {
                    $this->startNewRound($match);
                }
            }, delaySeconds: $delaySeconds);
        }

        // 5. Retornar resultado con información adicional
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

    /**
     * Verificar si el juego ha terminado.
     *
     * Usa RoundManager para determinar esto.
     *
     * @param GameMatch $match
     * @return bool
     */
    protected function isGameComplete(GameMatch $match): bool
    {
        $roundManager = RoundManager::fromArray($match->game_state);
        return $roundManager->isGameComplete();
    }

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

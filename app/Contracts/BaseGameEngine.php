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
     *
     * FLUJO:
     * 1. Procesar acción específica del juego
     * 2. Consultar a RoundManager si debe terminar la ronda
     * 3. Si termina: finalizar ronda y programar siguiente
     * 4. Retornar resultado
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
        $actionResult = $this->processRoundAction($match, $player, $data);

        // 2. Obtener RoundManager
        $roundManager = RoundManager::fromArray($match->game_state);

        // 3. Obtener todos los resultados de jugadores
        $playerResults = $this->getAllPlayerResults($match);

        // 4. Preguntar a RoundManager si la ronda debe terminar
        $roundStatus = $roundManager->shouldEndSimultaneousRound($playerResults);

        // 5. Actuar según decisión de RoundManager
        if ($roundStatus['should_end']) {
            Log::info("[{$this->getGameSlug()}] Round ending", [
                'match_id' => $match->id,
                'reason' => $roundStatus['reason']
            ]);

            // Finalizar ronda actual
            $this->endCurrentRound($match);

            // Programar siguiente ronda vía RoundManager
            $matchId = $match->id;
            $roundManager->scheduleNextRound(function () use ($matchId) {
                $match = GameMatch::find($matchId);
                if ($match && !$this->isGameComplete($match)) {
                    $this->startNewRound($match);
                }
            }, delaySeconds: 5);
        }

        // 6. Retornar resultado con información adicional
        return array_merge($actionResult, [
            'round_status' => $roundStatus,
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

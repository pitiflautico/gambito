<?php

namespace App\Contracts;

use App\Models\GameMatch;
use App\Models\Player;

/**
 * Contrato que todos los motores de juego deben implementar.
 *
 * Cada juego en games/{slug}/ debe tener una clase Engine que implemente esta interfaz.
 * Esto garantiza que todos los juegos tengan los métodos básicos necesarios para funcionar
 * en la plataforma.
 */
interface GameEngineInterface
{
    /**
     * Inicializar el juego cuando comienza una partida.
     *
     * Este método se llama una sola vez al inicio de la partida.
     * Debe configurar el estado inicial del juego en $match->game_state.
     *
     * @param GameMatch $match La partida que se está iniciando
     * @return void
     */
    public function initialize(GameMatch $match): void;

    /**
     * Procesar una acción de un jugador.
     *
     * Este método se llama cada vez que un jugador realiza una acción
     * (ej: dibujar, responder, votar, etc.).
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que realizó la acción
     * @param string $action El tipo de acción (ej: 'draw', 'answer', 'vote')
     * @param array $data Datos adicionales de la acción
     * @return array Resultado de la acción (puede incluir eventos a emitir, cambios de estado, etc.)
     */
    public function processAction(GameMatch $match, Player $player, string $action, array $data): array;

    /**
     * Verificar si hay un ganador.
     *
     * Este método se llama después de cada acción o al final de cada ronda
     * para determinar si la partida ha terminado y hay un ganador.
     *
     * @param GameMatch $match La partida actual
     * @return Player|null El jugador ganador, o null si aún no hay ganador
     */
    public function checkWinCondition(GameMatch $match): ?Player;

    /**
     * Obtener el estado actual del juego para un jugador específico.
     *
     * Este método devuelve la información que debe ver un jugador en particular.
     * Puede filtrar información secreta según el rol del jugador.
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que solicita el estado
     * @return array Estado del juego visible para ese jugador
     */
    public function getGameStateForPlayer(GameMatch $match, Player $player): array;

    /**
     * Avanzar a la siguiente fase/ronda del juego.
     *
     * Este método se llama cuando una fase o ronda termina.
     * Debe actualizar el game_state con la nueva fase/ronda.
     *
     * @param GameMatch $match La partida actual
     * @return void
     */
    public function advancePhase(GameMatch $match): void;

    /**
     * Manejar la desconexión de un jugador.
     *
     * Este método se llama cuando un jugador se desconecta.
     * El juego puede decidir cómo manejar esto (pausar, eliminar jugador, etc.).
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que se desconectó
     * @return void
     */
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void;

    /**
     * Manejar la reconexión de un jugador.
     *
     * Este método se llama cuando un jugador se reconecta.
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que se reconectó
     * @return void
     */
    public function handlePlayerReconnect(GameMatch $match, Player $player): void;

    /**
     * Finalizar la partida.
     *
     * Este método se llama cuando la partida termina.
     * Debe calcular puntuaciones finales, determinar ganador, etc.
     *
     * @param GameMatch $match La partida que está finalizando
     * @return array Datos finales de la partida (ranking, estadísticas, etc.)
     */
    public function finalize(GameMatch $match): array;
}

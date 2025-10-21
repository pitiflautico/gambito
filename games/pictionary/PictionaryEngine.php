<?php

namespace Games\Pictionary;

use App\Contracts\GameEngineInterface;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

/**
 * Motor del juego Pictionary.
 *
 * Implementación monolítica del juego Pictionary donde un jugador dibuja
 * una palabra mientras los demás intentan adivinarla.
 *
 * En Fase 4 se refactorizará para usar módulos opcionales (Turn, Scoring, Timer, Roles).
 */
class PictionaryEngine implements GameEngineInterface
{
    /**
     * Inicializar el juego cuando comienza una partida.
     *
     * Setup inicial:
     * - Cargar palabras desde words.json
     * - Asignar orden de turnos aleatorio
     * - Inicializar puntuaciones en 0
     * - Establecer ronda 1, turno 1
     *
     * @param GameMatch $match La partida que se está iniciando
     * @return void
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("Initializing Pictionary game", ['match_id' => $match->id]);

        // TODO: Implementar en Task 6.0 - Pictionary Game Logic
        // - Cargar palabras desde words.json
        // - Asignar orden de turnos (aleatorio)
        // - Inicializar game_state

        $match->game_state = [
            'phase' => 'lobby',
            'round' => 0,
            'current_turn' => 0,
            'current_drawer_id' => null,
            'current_word' => null,
            'turn_order' => [], // Se llenará con IDs de jugadores en orden
            'words_used' => [],
            'eliminated_this_round' => [],
            'rounds_total' => 5,
        ];

        $match->save();
    }

    /**
     * Procesar una acción de un jugador.
     *
     * Acciones soportadas:
     * - 'draw': Trazo en el canvas (Task 7.0 - WebSockets)
     * - 'answer': Jugador intenta responder
     * - 'confirm_answer': Dibujante confirma si respuesta es correcta
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que realizó la acción
     * @param string $action El tipo de acción
     * @param array $data Datos adicionales de la acción
     * @return array Resultado de la acción
     */
    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        Log::info("Processing action", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'action' => $action,
        ]);

        // TODO: Implementar en Task 6.0 - Pictionary Game Logic

        return match ($action) {
            'draw' => $this->handleDrawAction($match, $player, $data),
            'answer' => $this->handleAnswerAction($match, $player, $data),
            'confirm_answer' => $this->handleConfirmAnswer($match, $player, $data),
            default => ['success' => false, 'error' => 'Unknown action'],
        };
    }

    /**
     * Verificar si hay un ganador.
     *
     * Condición de victoria: El jugador con más puntos después de X rondas.
     *
     * @param GameMatch $match La partida actual
     * @return Player|null El jugador ganador, o null si aún no hay ganador
     */
    public function checkWinCondition(GameMatch $match): ?Player
    {
        $gameState = $match->game_state;

        // Si no se han completado todas las rondas, no hay ganador aún
        if ($gameState['round'] < $gameState['rounds_total']) {
            return null;
        }

        // TODO: Implementar en Task 6.0
        // Encontrar jugador con mayor puntuación

        return null;
    }

    /**
     * Obtener el estado actual del juego para un jugador específico.
     *
     * Información visible según rol:
     * - Dibujante: Ve la palabra secreta, canvas, tiempo restante
     * - Adivinadores: Ven canvas, jugadores, NO ven la palabra
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que solicita el estado
     * @return array Estado del juego visible para ese jugador
     */
    public function getGameStateForPlayer(GameMatch $match, Player $player): array
    {
        $gameState = $match->game_state;
        $isDrawer = ($gameState['current_drawer_id'] === $player->id);

        $state = [
            'phase' => $gameState['phase'],
            'round' => $gameState['round'],
            'is_drawer' => $isDrawer,
            'current_drawer_id' => $gameState['current_drawer_id'],
            'eliminated_this_round' => $gameState['eliminated_this_round'],
            // TODO: Añadir más datos en Task 6.0
        ];

        // Solo el dibujante ve la palabra
        if ($isDrawer) {
            $state['word'] = $gameState['current_word'];
        }

        return $state;
    }

    /**
     * Avanzar a la siguiente fase/ronda del juego.
     *
     * Fases:
     * 1. lobby -> drawing (al iniciar)
     * 2. drawing -> scoring (al terminar turno)
     * 3. scoring -> drawing (siguiente turno) o -> results (fin de partida)
     *
     * @param GameMatch $match La partida actual
     * @return void
     */
    public function advancePhase(GameMatch $match): void
    {
        // TODO: Implementar en Task 6.0 - Pictionary Game Logic
        Log::info("Advancing phase", ['match_id' => $match->id]);
    }

    /**
     * Manejar la desconexión de un jugador.
     *
     * Estrategia:
     * - Si es el dibujante: Pausar turno, esperar 2 min, si no vuelve -> skip turno
     * - Si es adivinador: Marcar como desconectado, puede reconectar
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que se desconectó
     * @return void
     */
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void
    {
        Log::warning("Player disconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id,
        ]);

        // TODO: Implementar lógica de desconexión en Task 6.0
    }

    /**
     * Manejar la reconexión de un jugador.
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que se reconectó
     * @return void
     */
    public function handlePlayerReconnect(GameMatch $match, Player $player): void
    {
        Log::info("Player reconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id,
        ]);

        // TODO: Sincronizar estado actual en Task 7.0 - WebSockets
    }

    /**
     * Finalizar la partida.
     *
     * Calcula puntuaciones finales, determina ganador, genera estadísticas.
     *
     * @param GameMatch $match La partida que está finalizando
     * @return array Datos finales de la partida
     */
    public function finalize(GameMatch $match): array
    {
        Log::info("Finalizing Pictionary game", ['match_id' => $match->id]);

        // TODO: Implementar en Task 6.0
        // - Calcular ranking final
        // - Determinar ganador
        // - Generar estadísticas

        return [
            'winner' => null,
            'ranking' => [],
            'statistics' => [],
        ];
    }

    // ========================================================================
    // MÉTODOS PRIVADOS (Lógica interna)
    // ========================================================================

    /**
     * Manejar acción de dibujar en el canvas.
     */
    private function handleDrawAction(GameMatch $match, Player $player, array $data): array
    {
        // TODO: Implementar en Task 7.0 - WebSockets
        // - Validar que el jugador es el dibujante
        // - Broadcast del trazo a todos los espectadores

        return ['success' => true];
    }

    /**
     * Manejar intento de respuesta de un adivinador.
     */
    private function handleAnswerAction(GameMatch $match, Player $player, array $data): array
    {
        // TODO: Implementar en Task 6.0
        // - Validar que el jugador no es el dibujante
        // - Validar que no está eliminado en esta ronda
        // - Notificar al dibujante para confirmación

        return ['success' => true, 'awaiting_confirmation' => true];
    }

    /**
     * Manejar confirmación del dibujante (respuesta correcta/incorrecta).
     */
    private function handleConfirmAnswer(GameMatch $match, Player $player, array $data): array
    {
        // TODO: Implementar en Task 6.0
        // - Validar que el jugador es el dibujante
        // - Si es correcta: Calcular puntos, terminar ronda
        // - Si es incorrecta: Eliminar jugador de esta ronda

        return ['success' => true];
    }
}

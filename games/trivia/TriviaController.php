<?php

namespace Games\Trivia;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Services\Modules\SessionManager\SessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TriviaController extends Controller
{
    /**
     * Mostrar la vista del juego de Trivia.
     */
    public function game(string $roomCode)
    {
        $room = Room::where('code', $roomCode)->firstOrFail();

        // Verificar que el juego sea Trivia
        if ($room->game->slug !== 'trivia') {
            abort(404, 'Esta sala no es de Trivia');
        }

        // Obtener el match actual
        $match = GameMatch::where('room_id', $room->id)
            ->whereNotNull('started_at')
            ->whereNull('finished_at')
            ->first();

        if (!$match) {
            abort(404, 'No hay una partida en progreso');
        }

        // Obtener jugadores del match
        $players = $match->players->map(function ($player) {
            return [
                'id' => $player->id,
                'name' => $player->name,
                'is_connected' => $player->is_connected,
            ];
        });

        // Obtener el jugador actual usando SessionManager
        $player = SessionManager::getCurrentPlayer($match);

        if (!$player) {
            return redirect()->route('rooms.lobby', ['code' => $roomCode])
                ->with('error', 'Debes unirte a la partida primero');
        }

        // Cargar eventos base (comunes a todos los juegos)
        // Usar require directo en lugar de config() para evitar problemas de cache
        $baseEventsPath = config_path('game-events.php');
        $baseEventsConfig = require $baseEventsPath;
        $baseEvents = $baseEventsConfig['base_events'] ?? [];

        \Log::info('Base events loaded:', ['base' => $baseEvents]);

        // Cargar eventos específicos de Trivia desde capabilities.json
        $capabilitiesPath = base_path("games/trivia/capabilities.json");
        $capabilities = json_decode(file_get_contents($capabilitiesPath), true);

        // Combinar eventos base con eventos específicos de Trivia
        $eventConfig = [
            'channel' => $capabilities['event_config']['channel'] ?? 'room.{roomCode}',
            'events' => array_merge(
                $baseEvents['events'] ?? [],
                $capabilities['event_config']['events'] ?? []
            ),
        ];

        \Log::info('Final eventConfig:', ['config' => $eventConfig]);

        return view('trivia::game', [
            'room' => $room,
            'match' => $match,
            'players' => $players,
            'playerId' => $player->id,
            'eventConfig' => $eventConfig,
        ]);
    }

    /**
     * Procesar respuesta de un jugador.
     */
    public function answer(Request $request)
    {
        $validated = $request->validate([
            'room_code' => 'required|string',
            'player_id' => 'required|integer',
            'answer' => 'required|integer|min:0|max:3',
        ]);

        try {
            $room = Room::where('code', $validated['room_code'])->firstOrFail();
            $match = GameMatch::where('room_id', $room->id)
                ->whereNotNull('started_at')
                ->whereNull('finished_at')
                ->firstOrFail();

            // Obtener el jugador del request y verificar que pertenezca al match
            $player = Player::findOrFail($validated['player_id']);

            if ($player->match_id !== $match->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'No perteneces a esta partida'
                ], 403);
            }

            // Procesar respuesta con el engine
            $engine = new TriviaEngine();
            $result = $engine->processAction($match, $player, 'answer', [
                'answer' => $validated['answer']
            ]);

            // Extraer información relevante del resultado
            $roundStatus = $result['round_status'] ?? [];

            return response()->json([
                'success' => $result['success'] ?? false,
                'is_correct' => $result['is_correct'] ?? false,
                'message' => $result['message'] ?? null,
                'question_ended' => $roundStatus['should_end'] ?? false,
                'round_status' => $roundStatus,
            ]);
        } catch (\Exception $e) {
            Log::error('[TriviaController] Error processing answer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error al procesar la respuesta'
            ], 500);
        }
    }

    /**
     * Countdown terminado en el frontend.
     *
     * Todos los frontends envían esta señal después de 5 segundos.
     * Solo el PRIMERO que llega inicia la siguiente ronda, el resto se desecha.
     */
    public function countdownEnded(Request $request)
    {
        $validated = $request->validate([
            'room_code' => 'required|string',
            'question_index' => 'required|integer',  // ← Cambiar a question_index
        ]);

        try {
            $room = Room::where('code', $validated['room_code'])->firstOrFail();
            $match = GameMatch::where('room_id', $room->id)
                ->whereNotNull('started_at')
                ->whereNull('finished_at')
                ->firstOrFail();

            $engine = new TriviaEngine();
            $gameState = $match->game_state;

            // Verificar si el juego ya terminó
            if ($engine->checkIfGameComplete($match)) {
                return response()->json([
                    'success' => false,
                    'error' => 'El juego ya terminó',
                    'game_complete' => true
                ]);
            }

            // Obtener índice de pregunta actual del backend
            $currentQuestionIndex = $gameState['current_question_index'] ?? 0;

            // ✅ VERIFICACIÓN: Si ya avanzó a la siguiente pregunta, otro frontend ya procesó
            if ($currentQuestionIndex !== $validated['question_index']) {
                Log::info('[TriviaController] Countdown for old question - discarding', [
                    'match_id' => $match->id,
                    'requested_index' => $validated['question_index'],
                    'current_index' => $currentQuestionIndex
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Old question - already processed',
                    'already_started' => true
                ]);
            }

            // ✅ VERIFICACIÓN ADICIONAL: Si ya estamos en fase "question", otro frontend ya procesó
            if (($gameState['phase'] ?? '') === 'question') {
                Log::info('[TriviaController] Already in question phase - discarding', [
                    'match_id' => $match->id,
                    'question_index' => $validated['question_index']
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Already in question phase',
                    'already_started' => true
                ]);
            }

            // ✅ ESTE ES EL PRIMERO: Iniciar siguiente ronda
            Log::info('[TriviaController] Processing countdown - starting next question', [
                'match_id' => $match->id,
                'question_index' => $validated['question_index']
            ]);

            $engine->advanceToNextRound($match);

            return response()->json([
                'success' => true,
                'message' => 'Next round started'
            ]);
        } catch (\Exception $e) {
            Log::error('[TriviaController] Error processing countdown', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error al procesar countdown'
            ], 500);
        }
    }
}

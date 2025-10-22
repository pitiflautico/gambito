<?php

namespace Games\Trivia;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
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

        // Obtener player ID de la sesión
        $playerId = session('player_id');

        // Cargar configuración de eventos desde capabilities.json
        $capabilitiesPath = base_path("games/trivia/capabilities.json");
        $capabilities = json_decode(file_get_contents($capabilitiesPath), true);
        $eventConfig = $capabilities['event_config'] ?? null;

        return view('trivia::game', [
            'room' => $room,
            'match' => $match,
            'players' => $players,
            'playerId' => $playerId,
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
            $player = Player::findOrFail($validated['player_id']);
            $match = GameMatch::where('room_id', $room->id)
                ->whereNotNull('started_at')
                ->whereNull('finished_at')
                ->firstOrFail();

            // Verificar que el jugador pertenezca a la sala
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

            return response()->json([
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? null,
                'data' => $result
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
}

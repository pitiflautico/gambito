<?php

namespace Games\Uno;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * UNO Game Controller
 *
 * Maneja las peticiones HTTP del juego UNO
 */
class UnoController extends Controller
{
    /**
     * Mostrar la vista del juego
     */
    public function game(string $roomCode)
    {
        $room = Room::where('code', $roomCode)->firstOrFail();

        // Obtener el match activo
        $match = GameMatch::where('room_id', $room->id)
            ->whereNotNull('started_at')
            ->whereNull('finished_at')
            ->first();

        if (!$match) {
            return redirect()->route('rooms.show', $roomCode)
                ->with('error', 'El juego no ha comenzado aún');
        }

        // Obtener el jugador actual
        $user = Auth::user() ?? session('guest_user');
        $player = Player::where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$player) {
            return redirect()->route('rooms.show', $roomCode)
                ->with('error', 'No eres parte de este juego');
        }

        // Cargar event_config desde capabilities.json
        $capabilitiesPath = base_path("games/uno/capabilities.json");
        $capabilities = json_decode(file_get_contents($capabilitiesPath), true);
        $eventConfig = $capabilities['event_config'] ?? null;

        return view('uno::game', [
            'room' => $room,
            'match' => $match,
            'player' => $player,
            'code' => $roomCode,
            'playerId' => $player->id,
            'eventConfig' => $eventConfig,
        ]);
    }

    /**
     * Procesar acción del jugador
     */
    public function action(Request $request, string $roomCode)
    {
        try {
            $room = Room::where('code', $roomCode)->firstOrFail();
            $match = GameMatch::where('room_id', $room->id)
                ->whereNotNull('started_at')
                ->whereNull('finished_at')
                ->firstOrFail();

            // Obtener el jugador actual
            $user = Auth::user() ?? session('guest_user');
            $player = Player::where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Obtener el engine
            $engine = app($room->game->getEngineClass());

            // Procesar la acción
            $result = $engine->processAction(
                $match,
                $player,
                $request->input('action'),
                $request->all()
            );

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('[UNO] Error processing action', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Obtener el estado del juego para el jugador actual
     */
    public function getState(Request $request, string $roomCode)
    {
        try {
            $room = Room::where('code', $roomCode)->firstOrFail();
            $match = GameMatch::where('room_id', $room->id)
                ->whereNotNull('started_at')
                ->whereNull('finished_at')
                ->firstOrFail();

            // Obtener el jugador actual
            $user = Auth::user() ?? session('guest_user');
            $player = Player::where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Obtener el engine
            $engine = app($room->game->getEngineClass());

            // Obtener el estado para este jugador
            $state = $engine->getGameStateForPlayer($match, $player);

            return response()->json([
                'success' => true,
                'state' => $state
            ]);

        } catch (\Exception $e) {
            Log::error('[UNO] Error getting state', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
}

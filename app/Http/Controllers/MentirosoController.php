<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MentirosoController extends Controller
{
    /**
     * Submit a vote for the current statement
     */
    public function submitVote(Request $request, string $roomCode): JsonResponse
    {
        $validated = $request->validate([
            'player_id' => 'required|integer|exists:players,id',
            'vote' => 'required|boolean',
        ]);

        Log::info('[MentirosoController] Vote received', [
            'room_code' => $roomCode,
            'player_id' => $validated['player_id'],
            'vote' => $validated['vote']
        ]);

        try {
            // Find room and active match
            $room = Room::where('code', $roomCode)->firstOrFail();
            $match = $room->match;

            if (!$match) {
                Log::error('[MentirosoController] No active match found', ['room_code' => $roomCode]);
                return response()->json([
                    'success' => false,
                    'message' => 'No active match found'
                ], 404);
            }

            // Get player from game_state (NO query to DB - usar estrategia Redis/cache)
            $playerId = $validated['player_id'];
            $players = $match->game_state['_config']['players'] ?? [];

            // Players is an indexed array, need to find by id
            $playerData = collect($players)->firstWhere('id', $playerId);

            if (!$playerData) {
                Log::error('[MentirosoController] Player not found in match', [
                    'player_id' => $playerId,
                    'players_in_match' => collect($players)->pluck('id')->toArray()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Player not in this match'
                ], 403);
            }

            // Crear Player object desde datos en memoria (NO query!)
            $player = new Player();
            $player->id = $playerData['id'];
            $player->name = $playerData['name'];
            $player->user_id = $playerData['user_id'];
            $player->exists = true;

            Log::info('[MentirosoController] Calling processAction', [
                'player_id' => $player->id,
                'player_name' => $player->name,
                'action' => 'vote',
                'vote' => $validated['vote']
            ]);

            // Process vote through match (uses standard action flow)
            // BaseGameEngine.processAction() maneja automáticamente should_end_turn via strategies
            $result = $match->processAction($player, 'vote', $validated);

            Log::info('[MentirosoController] processAction result', $result);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('[MentirosoController] Error submitting vote:', [
                'room_code' => $roomCode,
                'player_id' => $validated['player_id'],
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error submitting vote'
            ], 500);
        }
    }
}

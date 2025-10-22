<?php

namespace Games\Pictionary;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\Room;
use Games\Pictionary\Events\CanvasDrawEvent;
use Games\Pictionary\Events\PlayerAnsweredEvent;
use Games\Pictionary\Events\AnswerConfirmedEvent;
use Illuminate\Http\Request;

class PictionaryController extends Controller
{
    /**
     * Mostrar la vista del juego de Pictionary.
     */
    public function game(string $roomCode)
    {
        $room = Room::where('code', $roomCode)->firstOrFail();

        // Verificar que el juego sea Pictionary
        if ($room->game->slug !== 'pictionary') {
            abort(404, 'Esta sala no es de Pictionary');
        }

        // Obtener el match actual
        $match = GameMatch::where('room_id', $room->id)
            ->whereNotNull('started_at')
            ->whereNull('finished_at')
            ->first();

        if (!$match) {
            abort(404, 'No hay una partida en progreso');
        }

        // Obtener el jugador actual (guest o autenticado)
        $player = null;
        $playerId = null;

        if (\Auth::check()) {
            $player = $match->players()->where('user_id', \Auth::id())->first();
        } elseif (session()->has('guest_session_id')) {
            $guestSessionId = session('guest_session_id');
            $player = $match->players()->where('session_id', $guestSessionId)->first();
        }

        if (!$player) {
            return redirect()->route('rooms.lobby', ['code' => $roomCode])
                ->with('error', 'Debes unirte a la partida primero');
        }

        $playerId = $player->id;

        // Obtener el rol del jugador desde el motor del juego
        $gameState = $match->game_state ?? [];
        $currentDrawerId = $gameState['current_drawer_id'] ?? null;
        $role = ($player->id === $currentDrawerId) ? 'drawer' : 'guesser';

        // Retornar la vista usando el namespace del juego
        return view('games.pictionary.canvas', compact('room', 'match', 'playerId', 'role'));
    }

    /**
     * Mostrar demo del canvas (solo para desarrollo)
     */
    public function demo(Request $request)
    {
        // Datos de prueba para visualizar el diseño
        $room = (object) [
            'id' => 1,
            'name' => 'Sala de Prueba',
            'code' => 'DEMO123',
        ];

        $match = (object) [
            'id' => 1,
        ];

        // Determinar rol basado en query parameter
        $role = $request->query('role', 'drawer'); // 'drawer' o 'guesser'

        // Crear ID de jugador según el rol
        $playerId = $role === 'drawer' ? 1 : 2;

        // Por ahora cargamos la vista directamente desde el archivo
        // TODO Task 6.0: Registrar el namespace de vistas del juego
        return view('games.pictionary.canvas', compact('room', 'match', 'playerId', 'role'));
    }

    /**
     * Broadcast evento de dibujo a todos los jugadores en la sala
     */
    public function broadcastDraw(Request $request)
    {
        $request->validate([
            'room_code' => 'required|string',
            'match_id' => 'required|integer',
            'stroke' => 'required|array',
            'stroke.x0' => 'required|numeric',
            'stroke.y0' => 'required|numeric',
            'stroke.x1' => 'required|numeric',
            'stroke.y1' => 'required|numeric',
            'stroke.color' => 'required|string',
            'stroke.size' => 'required|numeric',
        ]);

        // En producción, buscaríamos la sala real
        // Por ahora trabajamos con el código directamente
        $roomCode = $request->input('room_code');
        $strokeData = $request->input('stroke');

        // Emitir evento de dibujo
        event(new CanvasDrawEvent($roomCode, 'draw', $strokeData));

        return response()->json(['success' => true]);
    }

    /**
     * Broadcast evento de limpiar canvas a todos los jugadores en la sala
     */
    public function broadcastClear(Request $request)
    {
        $request->validate([
            'room_code' => 'required|string',
            'match_id' => 'required|integer',
        ]);

        $roomCode = $request->input('room_code');

        // Emitir evento de limpiar
        event(new CanvasDrawEvent($roomCode, 'clear'));

        return response()->json(['success' => true]);
    }

    /**
     * Broadcast cuando un jugador pulsa "¡YO SÉ!"
     */
    public function playerAnswered(Request $request)
    {
        $request->validate([
            'room_code' => 'required|string',
            'match_id' => 'required|integer',
            'player_id' => 'required|integer',
            'player_name' => 'required|string',
        ]);

        try {
            $matchId = $request->input('match_id');
            $playerId = $request->input('player_id');
            $playerName = $request->input('player_name');
            $roomCode = $request->input('room_code');

            \Log::info("Player answered request", [
                'match_id' => $matchId,
                'player_id' => $playerId,
                'player_name' => $playerName,
            ]);

            // Buscar match con relaciones
            $match = GameMatch::with(['room.game'])->findOrFail($matchId);
            $player = \App\Models\Player::findOrFail($playerId);

            // Cargar el engine del juego
            $game = $match->room->game;
            if (!$game) {
                throw new \Exception('Game not found for this match');
            }

            $engineClass = $game->getEngineClass();
            if (!$engineClass || !class_exists($engineClass)) {
                throw new \Exception('Engine class not found: ' . $engineClass);
            }

            $engine = new $engineClass();

            // Procesar la acción a través del engine
            $result = $engine->processAction($match, $player, 'answer', [
                'player_name' => $playerName,
            ]);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Error al procesar respuesta'
                ], 400);
            }

            // El engine ya emitió el PlayerAnsweredEvent
            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            \Log::error("Error processing player answered", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error del servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Broadcast cuando el drawer confirma la respuesta
     */
    public function confirmAnswer(Request $request)
    {
        $request->validate([
            'room_code' => 'required|string',
            'match_id' => 'required|integer',
            'drawer_id' => 'required|integer',
            'guesser_id' => 'required|integer',
            'is_correct' => 'required|boolean',
        ]);

        try {
            $matchId = $request->input('match_id');
            $drawerId = $request->input('drawer_id');
            $guesserId = $request->input('guesser_id');
            $isCorrect = $request->input('is_correct');

            \Log::info("Confirm answer request", [
                'match_id' => $matchId,
                'drawer_id' => $drawerId,
                'guesser_id' => $guesserId,
                'is_correct' => $isCorrect
            ]);

            // Buscar match con relaciones cargadas
            $match = GameMatch::with(['room.game'])->findOrFail($matchId);
            $drawer = \App\Models\Player::findOrFail($drawerId);
            $guesser = \App\Models\Player::findOrFail($guesserId);

            // Cargar el engine del juego
            $game = $match->room->game;

            if (!$game) {
                throw new \Exception('Game not found for this match');
            }

            $engineClass = $game->getEngineClass();

            if (!$engineClass) {
                throw new \Exception('Engine class not found for game: ' . $game->slug);
            }

            if (!class_exists($engineClass)) {
                throw new \Exception('Engine class does not exist: ' . $engineClass);
            }

            $engine = new $engineClass();

            // Procesar la confirmación a través del engine
            $result = $engine->processAction($match, $drawer, 'confirm_answer', [
                'is_correct' => $isCorrect,
                'guesser_id' => $guesserId,
            ]);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Error al confirmar respuesta'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            \Log::error("Error confirming answer", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error del servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Avanzar a la siguiente fase del juego
     */
    public function advancePhase(Request $request)
    {
        try {
            $request->validate([
                'room_code' => 'required|string',
                'match_id' => 'required|integer',
            ]);

            $roomCode = $request->input('room_code');
            $matchId = $request->input('match_id');

            \Log::info("Advance phase request", [
                'match_id' => $matchId,
                'room_code' => $roomCode,
            ]);

            // Obtener la partida con relaciones
            $match = GameMatch::with(['room.game'])->findOrFail($matchId);

            // Verificar que el room code coincide
            if ($match->room->code !== $roomCode) {
                return response()->json([
                    'success' => false,
                    'error' => 'Room code mismatch'
                ], 400);
            }

            // Obtener el engine del juego
            $game = $match->room->game;
            $engineClass = $game->getEngineClass();
            $engine = new $engineClass();

            // Avanzar de fase
            $engine->advancePhase($match);

            \Log::info("Phase advanced successfully", [
                'match_id' => $matchId,
                'new_phase' => $match->fresh()->game_state['phase'] ?? 'unknown'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Phase advanced successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error("Error advancing phase", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error del servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener palabra secreta para el dibujante
     */
    public function getWord(Request $request)
    {
        try {
            $request->validate([
                'match_id' => 'required|integer',
                'player_id' => 'required|integer',
            ]);

            $matchId = $request->input('match_id');
            $playerId = $request->input('player_id');

            // Obtener la partida
            $match = GameMatch::findOrFail($matchId);
            $gameState = $match->game_state;

            // Verificar que el jugador es el dibujante actual
            if ($gameState['current_drawer_id'] !== $playerId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Solo el dibujante puede ver la palabra'
                ], 403);
            }

            // Retornar la palabra
            return response()->json([
                'success' => true,
                'word' => $gameState['current_word']
            ]);

        } catch (\Exception $e) {
            \Log::error("Error getting word", [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error del servidor: ' . $e->getMessage()
            ], 500);
        }
    }
}

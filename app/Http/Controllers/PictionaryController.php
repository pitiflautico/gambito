<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Room;
use Games\Pictionary\Events\CanvasDrawEvent;
use Games\Pictionary\Events\PlayerAnsweredEvent;
use Games\Pictionary\Events\AnswerConfirmedEvent;
use Illuminate\Http\Request;

class PictionaryController extends Controller
{
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

        $roomCode = $request->input('room_code');
        $playerId = $request->input('player_id');
        $playerName = $request->input('player_name');

        // Emitir evento
        event(new PlayerAnsweredEvent($roomCode, $playerId, $playerName));

        return response()->json(['success' => true]);
    }

    /**
     * Broadcast cuando el drawer confirma la respuesta
     */
    public function confirmAnswer(Request $request)
    {
        $request->validate([
            'room_code' => 'required|string',
            'match_id' => 'required|integer',
            'player_id' => 'required|integer',
            'player_name' => 'required|string',
            'is_correct' => 'required|boolean',
        ]);

        $roomCode = $request->input('room_code');
        $playerId = $request->input('player_id');
        $playerName = $request->input('player_name');
        $isCorrect = $request->input('is_correct');

        // Emitir evento
        event(new AnswerConfirmedEvent($roomCode, $playerId, $playerName, $isCorrect));

        return response()->json(['success' => true]);
    }
}

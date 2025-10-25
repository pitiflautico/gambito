<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\Core\PlayerSessionService;
use App\Services\Core\RoomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Controlador para la vista del juego (FASE 3).
 *
 * Responsabilidad: Renderizar la vista específica del juego cuando status = PLAYING
 */
class PlayController extends Controller
{
    protected RoomService $roomService;
    protected PlayerSessionService $playerSessionService;

    public function __construct(
        RoomService $roomService,
        PlayerSessionService $playerSessionService
    ) {
        $this->roomService = $roomService;
        $this->playerSessionService = $playerSessionService;
    }

    /**
     * Mostrar la vista del juego activo.
     *
     * FASE 3: Game - El engine ya está inicializado, mostramos la interfaz del juego
     */
    public function show(string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            abort(404, 'Sala no encontrada');
        }

        // Cargar relaciones necesarias
        $room->load(['game', 'match.players', 'master']);

        // VALIDACIÓN: Solo permitir acceso si status = PLAYING
        if ($room->status !== Room::STATUS_PLAYING) {
            return redirect()->route('rooms.show', ['code' => $code]);
        }

        // IMPORTANTE: Verificar si el master (creador) está conectado
        if (!$this->roomService->isMasterConnected($room)) {
            \Log::warning("Game - master disconnected, closing room", [
                'code' => $code,
                'master_id' => $room->master_id,
            ]);

            $this->roomService->closeRoom($room);

            return redirect()->route('home')
                ->with('error', 'El creador de la sala se desconectó. La sala ha sido cerrada.');
        }

        // Obtener el jugador actual (guest o autenticado)
        $player = null;
        if (Auth::check()) {
            $player = $room->match->players()->where('user_id', Auth::id())->first();
        } elseif ($this->playerSessionService->hasGuestSession()) {
            $guestData = $this->playerSessionService->getGuestData();
            $player = $room->match->players()->where('session_id', $guestData['session_id'])->first();
        }

        // Si no se encontró jugador, redirigir al lobby
        if (!$player) {
            return redirect()->route('rooms.lobby', ['code' => $code])
                ->with('error', 'Debes unirte a la partida primero');
        }

        $playerId = $player->id;

        // Obtener el rol del jugador desde el motor del juego
        $gameState = $room->match->game_state ?? [];
        $currentDrawerId = $gameState['current_drawer_id'] ?? null;
        $role = ($player->id === $currentDrawerId) ? 'drawer' : 'guesser';

        // Obtener lista de jugadores con sus datos
        $players = $room->match->players->map(function ($p) use ($currentDrawerId, $gameState) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'score' => $gameState['scores'][$p->id] ?? 0,
                'is_drawer' => ($p->id === $currentDrawerId),
                'is_eliminated' => in_array($p->id, $gameState['eliminated_this_round'] ?? [])
            ];
        });

        // Cargar event_config mergeando base events con eventos del juego
        $gameSlug = $room->game->slug;
        $capabilitiesPath = base_path("games/{$gameSlug}/capabilities.json");

        // Cargar base events
        $baseEventsPath = config_path('game-events.php');
        $baseEventsConfig = require $baseEventsPath;
        $baseEvents = $baseEventsConfig['base_events'] ?? [];

        // Cargar eventos del juego
        $gameEvents = [];
        $channel = 'room.{roomCode}';
        if (file_exists($capabilitiesPath)) {
            $capabilities = json_decode(file_get_contents($capabilitiesPath), true);
            $gameEvents = $capabilities['event_config']['events'] ?? [];
            $channel = $capabilities['event_config']['channel'] ?? 'room.{roomCode}';
        }

        // Merge eventos base + eventos del juego
        $eventConfig = [
            'channel' => $channel,
            'events' => array_merge(
                $baseEvents['events'] ?? [],
                $gameEvents
            ),
        ];

        // Renderizar vista específica del juego usando su namespace
        // CONVENCIÓN: La vista principal del juego SIEMPRE debe llamarse "game.blade.php"
        // Ubicación: games/{slug}/views/game.blade.php
        $gameViewName = "{$gameSlug}::game";
        if (view()->exists($gameViewName)) {
            return view($gameViewName, [
                'code' => $code,
                'room' => $room,
                'match' => $room->match,
                'playerId' => $playerId,
                'role' => $role,
                'eventConfig' => $eventConfig,
            ]);
        }

        // Fallback: Cargar vista genérica si el juego no tiene vista específica
        return view('rooms.show', compact('code', 'room', 'playerId', 'role', 'players', 'eventConfig'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Events\PlayerJoinedEvent;
use App\Events\PlayerLeftEvent;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Services\Core\PlayerSessionService;
use App\Services\Core\RoomService;
use App\Services\Core\GameConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoomController extends Controller
{
    /**
     * Room service.
     */
    protected RoomService $roomService;

    /**
     * Player session service.
     */
    protected PlayerSessionService $playerSessionService;

    /**
     * Game config service.
     */
    protected GameConfigService $gameConfigService;

    public function __construct(
        RoomService $roomService,
        PlayerSessionService $playerSessionService,
        GameConfigService $gameConfigService
    ) {
        $this->roomService = $roomService;
        $this->playerSessionService = $playerSessionService;
        $this->gameConfigService = $gameConfigService;
    }

    /**
     * Mostrar formulario para crear una sala.
     */
    public function create(Request $request)
    {
        // Obtener juegos activos
        $games = Game::active()->get();

        // Si se seleccionó un juego, obtener sus configuraciones
        $selectedGame = null;
        $gameConfig = null;
        $customizableSettings = [];

        if ($request->has('game_id')) {
            $selectedGame = Game::find($request->game_id);

            if ($selectedGame) {
                $gameConfig = $this->gameConfigService->getConfig($selectedGame->slug);
                $customizableSettings = $this->gameConfigService->getCustomizableSettings($selectedGame->slug);
            }
        }

        return view('rooms.create', compact('games', 'selectedGame', 'gameConfig', 'customizableSettings'));
    }

    /**
     * Crear una nueva sala.
     */
    public function store(Request $request)
    {
        // Validación básica
        $basicValidation = [
            'game_id' => 'required|exists:games,id',
            'max_players' => 'nullable|integer|min:1|max:100',
            'private' => 'nullable|boolean',
            'play_with_teams' => 'nullable|boolean',
        ];

        $game = Game::findOrFail($request->game_id);

        // Validar que el juego esté activo
        if (!$game->is_active) {
            return back()->withErrors(['game_id' => 'Este juego no está disponible actualmente.']);
        }

        // ========================================================================
        // VALIDACIÓN DINÁMICA: Agregar reglas de validación del juego
        // ========================================================================
        $gameValidationRules = $this->gameConfigService->getValidationRules($game->slug);
        $allRules = array_merge($basicValidation, $gameValidationRules);

        $validated = $request->validate($allRules);

        // ========================================================================
        // PREPARAR SETTINGS: Combinar settings básicos + configuraciones del juego
        // ========================================================================
        $settings = [];

        // Settings básicos
        if (isset($validated['max_players'])) {
            $settings['max_players'] = $validated['max_players'];
        }
        if (isset($validated['private'])) {
            $settings['private'] = $validated['private'];
        }
        if (isset($validated['play_with_teams']) && $validated['play_with_teams']) {
            $settings['play_with_teams'] = true;
        }

        // Settings específicos del juego (configuraciones customizables)
        $customizableSettings = $this->gameConfigService->getCustomizableSettings($game->slug);
        foreach (array_keys($customizableSettings) as $key) {
            if (isset($validated[$key])) {
                $settings[$key] = $validated[$key];
            }
        }

        try {
            // IMPORTANTE: Limpiar sesiones anteriores (guest y jugador)
            // Esto evita conflictos cuando el usuario crea una nueva sala
            // después de haber estado en otra sala
            $this->playerSessionService->clearAllSessions();

            // Desconectar al usuario de otras partidas activas
            Player::where('user_id', Auth::id())
                ->where('is_connected', true)
                ->whereHas('match', function ($query) {
                    $query->whereNull('finished_at');
                })
                ->update(['is_connected' => false]);

            // Crear sala
            $room = $this->roomService->createRoom($game, Auth::user(), $settings);

            // Crear partida asociada
            $match = GameMatch::create([
                'room_id' => $room->id,
                'game_state' => [],
            ]);

            // Crear jugador para el master
            Player::create([
                'match_id' => $match->id,
                'user_id' => Auth::id(),
                'name' => Auth::user()->name,
                'role' => 'master',
                'is_connected' => true,
                'last_ping' => now(),
            ]);

            return redirect()->route('rooms.lobby', ['code' => $room->code])
                ->with('success', 'Sala creada exitosamente. Código: ' . $room->code);
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Mostrar formulario para unirse a una sala.
     */
    public function join(Request $request)
    {
        $code = $request->query('code');

        return view('rooms.join', compact('code'));
    }

    /**
     * Procesar unión a una sala mediante código.
     */
    public function joinByCode(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $code = strtoupper($validated['code']);

        // Verificar formato
        if (!$this->roomService->isValidCodeFormat($code)) {
            return back()->withErrors(['code' => 'Código inválido.']);
        }

        // Buscar sala
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return back()->withErrors(['code' => 'No se encontró ninguna sala con ese código.']);
        }

        // Verificar estado de la sala
        if ($room->status === Room::STATUS_FINISHED) {
            return back()->withErrors(['code' => 'Esta sala ya ha terminado.']);
        }

        // Redirigir al lobby
        return redirect()->route('rooms.lobby', ['code' => $code]);
    }

    /**
     * Mostrar lobby de espera de una sala.
     *
     * @param string $code Código de la sala
     */
    public function lobby(string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            abort(404, 'Sala no encontrada');
        }

        \Log::info("Lobby accessed", [
            'code' => $code,
            'room_status' => $room->status,
            'user_id' => Auth::id(),
            'has_guest_session' => $this->playerSessionService->hasGuestSession(),
            'url' => request()->fullUrl(),
        ]);

        // Cargar relaciones necesarias
        $room->load(['game', 'master']);
        if ($room->match) {
            $room->match->load('players');
        }

        // Verificar que la sala no haya terminado
        if ($room->status === Room::STATUS_FINISHED) {
            \Log::warning("Lobby redirecting to home - room finished", ['code' => $code]);
            return redirect()->route('home')
                ->with('error', 'Esta sala ya ha terminado.');
        }

        // IMPORTANTE: Verificar si el master (creador) está conectado
        // Si el master no está conectado, cerrar la sala automáticamente
        if (!$this->roomService->isMasterConnected($room)) {
            \Log::warning("Lobby - master disconnected, closing room", [
                'code' => $code,
                'master_id' => $room->master_id,
            ]);

            $this->roomService->closeRoom($room);

            return redirect()->route('home')
                ->with('error', 'El creador de la sala se desconectó. La sala ha sido cerrada.');
        }

        // Si la sala ya está jugando, redirigir a la sala activa
        if ($room->status === Room::STATUS_PLAYING) {
            \Log::info("Lobby redirecting to show - room playing", ['code' => $code]);
            return redirect()->route('rooms.show', ['code' => $code]);
        }

        // Si está autenticado, verificar que esté en la partida como jugador
        if (Auth::check()) {
            $existingPlayer = $room->match->players()
                ->where('user_id', Auth::id())
                ->first();

            // Si no existe como jugador, agregarlo
            if (!$existingPlayer) {
                // Desconectar al usuario de otras partidas activas
                Player::where('user_id', Auth::id())
                    ->where('is_connected', true)
                    ->whereHas('match', function ($query) use ($room) {
                        $query->where('id', '!=', $room->match->id)
                              ->whereNull('finished_at');
                    })
                    ->update(['is_connected' => false]);

                $newPlayer = Player::create([
                    'match_id' => $room->match->id,
                    'user_id' => Auth::id(),
                    'name' => Auth::user()->name,
                    'role' => Auth::id() === $room->master_id ? 'master' : null,
                    'is_connected' => true,
                    'last_ping' => now(),
                ]);
                // Recargar jugadores
                $room->match->load('players');

                // Emitir evento de jugador unido
                $totalPlayers = $room->match->players()->count();
                event(new PlayerJoinedEvent($room, $newPlayer, $totalPlayers));
            }
        }

        // Si no está autenticado, verificar si ya está en esta partida
        if (!Auth::check()) {
            // Si no tiene sesión de invitado, pedir nombre
            if (!$this->playerSessionService->hasGuestSession()) {
                \Log::info("Lobby redirecting to guestName - no guest session", ['code' => $code]);
                return redirect()->route('rooms.guestName', ['code' => $code]);
            }

            $guestData = $this->playerSessionService->getGuestData();

            \Log::info("Lobby - guest session found", [
                'code' => $code,
                'session_id' => $guestData['session_id'] ?? null,
                'name' => $guestData['name'] ?? null,
            ]);

            // Verificar si ya existe como jugador en esta partida
            $existingPlayerInThisMatch = $room->match->players()
                ->where('session_id', $guestData['session_id'])
                ->first();

            // Si no está en esta partida, crear el jugador
            if (!$existingPlayerInThisMatch) {
                \Log::info("Lobby - creating guest player", [
                    'code' => $code,
                    'session_id' => $guestData['session_id'],
                    'name' => $guestData['name'],
                ]);

                try {
                    // Desconectar al jugador de otras partidas activas
                    Player::where('session_id', $guestData['session_id'])
                        ->where('is_connected', true)
                        ->whereHas('match', function ($query) use ($room) {
                            $query->where('id', '!=', $room->match->id)
                                  ->whereNull('finished_at');
                        })
                        ->update(['is_connected' => false]);

                    $newPlayer = $this->playerSessionService->createGuestPlayer($room->match, $guestData['name']);

                    // Recargar jugadores
                    $room->match->load('players');

                    // Emitir evento de jugador unido
                    $totalPlayers = $room->match->players()->count();
                    event(new PlayerJoinedEvent($room, $newPlayer, $totalPlayers));

                    \Log::info("Lobby - guest player created successfully", [
                        'code' => $code,
                        'player_id' => $newPlayer->id,
                    ]);
                } catch (\Exception $e) {
                    \Log::error("Lobby - error creating guest player", [
                        'code' => $code,
                        'error' => $e->getMessage(),
                    ]);

                    // Limpiar sesión de invitado corrupta
                    $this->playerSessionService->clearGuestSession();

                    return redirect()->route('rooms.guestName', ['code' => $code])
                        ->withErrors(['error' => 'Hubo un problema. Por favor, ingresa tu nombre nuevamente.']);
                }
            } else {
                \Log::info("Lobby - guest player already exists", [
                    'code' => $code,
                    'player_id' => $existingPlayerInThisMatch->id,
                ]);
            }
        }

        // Obtener estadísticas de la sala
        $stats = $this->roomService->getRoomStats($room);

        // URL de invitación y QR
        $inviteUrl = $this->roomService->getInviteUrl($room);
        $qrCodeUrl = $this->roomService->getQrCodeUrl($room);

        // Verificar si puede iniciar
        $canStart = $this->roomService->canStartGame($room);

        // Verificar si el usuario es el master
        $isMaster = Auth::check() && Auth::id() === $room->master_id;

        // Cargar configuración del juego para equipos
        $gameConfig = $room->game->config;

        return view('rooms.lobby', compact('room', 'stats', 'inviteUrl', 'qrCodeUrl', 'canStart', 'isMaster', 'gameConfig'));
    }

    /**
     * Mostrar formulario para pedir nombre de invitado.
     *
     * @param string $code Código de la sala
     */
    public function guestName(string $code)
    {
        $code = strtoupper($code);

        \Log::info("GuestName accessed", [
            'code' => $code,
            'url' => request()->fullUrl(),
        ]);

        // Verificar que la sala existe
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            \Log::error("GuestName - room not found", ['code' => $code]);
            abort(404, 'Sala no encontrada');
        }

        \Log::info("GuestName - room found", [
            'code' => $code,
            'room_status' => $room->status,
        ]);

        // Si el usuario está autenticado, redirigir al lobby directamente
        if (Auth::check()) {
            \Log::info("GuestName - user authenticated, redirecting to lobby");
            return redirect()->route('rooms.lobby', ['code' => $code]);
        }

        // Obtener nombre anterior si existe
        $previousName = null;
        if ($this->playerSessionService->hasGuestSession()) {
            $guestData = $this->playerSessionService->getGuestData();
            $previousName = $guestData['name'] ?? null;
        }

        return view('rooms.guest-name', compact('code', 'previousName'));
    }

    /**
     * Procesar el nombre del invitado y crear sesión temporal.
     *
     * @param string $code Código de la sala
     */
    public function storeGuestName(Request $request, string $code)
    {
        $validated = $request->validate([
            'player_name' => 'required|string|min:2|max:50',
        ]);

        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return back()->withErrors(['error' => 'Sala no encontrada.']);
        }

        // Crear sesión de invitado
        try {
            // IMPORTANTE: SIEMPRE limpiar sesión de invitado anterior antes de crear nueva
            // Esto evita conflictos de session_id duplicados
            if ($this->playerSessionService->hasGuestSession()) {
                $oldGuestData = $this->playerSessionService->getGuestData();
                \Log::info("Clearing old guest session before creating new one", [
                    'old_session_id' => $oldGuestData['session_id'] ?? null,
                    'old_name' => $oldGuestData['name'] ?? null,
                    'new_name' => $validated['player_name'],
                    'new_room' => $code,
                ]);

                // Desconectar sesión anterior de TODAS las partidas
                Player::where('session_id', $oldGuestData['session_id'])
                    ->where('is_connected', true)
                    ->update(['is_connected' => false]);

                // Limpiar la sesión anterior ANTES de crear la nueva
                $this->playerSessionService->clearGuestSession();
            }

            // Ahora sí, crear la nueva sesión limpia
            $this->playerSessionService->createGuestSession($validated['player_name']);

            \Log::info("New guest session created", [
                'name' => $validated['player_name'],
                'room' => $code,
            ]);

            return redirect()->route('rooms.lobby', ['code' => $code])
                ->with('success', '¡Bienvenido ' . $validated['player_name'] . '!');
        } catch (\Exception $e) {
            \Log::error("Error creating guest session", [
                'error' => $e->getMessage(),
                'code' => $code,
            ]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Mostrar sala activa (partida en curso).
     *
     * @param string $code Código de la sala
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

        // Si la sala está esperando, redirigir al lobby
        if ($room->status === Room::STATUS_WAITING) {
            return redirect()->route('rooms.lobby', ['code' => $code]);
        }

        // Si la sala terminó, mostrar resultados
        if ($room->status === Room::STATUS_FINISHED) {
            return redirect()->route('rooms.results', ['code' => $code]);
        }

        // IMPORTANTE: Verificar si el master (creador) está conectado
        // Si el master no está conectado, cerrar la sala automáticamente
        if (!$this->roomService->isMasterConnected($room)) {
            \Log::warning("Show - master disconnected, closing room", [
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
            // Usuario autenticado
            $player = $room->match->players()->where('user_id', Auth::id())->first();
        } elseif ($this->playerSessionService->hasGuestSession()) {
            // Invitado
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
        $gameViewName = "{$gameSlug}::canvas";
        if (view()->exists($gameViewName)) {
            return view($gameViewName, [
                'room' => $room,
                'match' => $room->match,
                'playerId' => $playerId,
                'role' => $role,
                'eventConfig' => $eventConfig,
            ]);
        }

        // Fallback: Cargar vista genérica si el juego no tiene vista específica
        return view('rooms.show', compact('room', 'playerId', 'role', 'players', 'eventConfig'));
    }

    /**
     * Mostrar resultados de una sala finalizada.
     *
     * @param string $code Código de la sala
     */
    public function results(string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            abort(404, 'Sala no encontrada');
        }

        // Cargar relaciones necesarias
        $room->load(['game', 'match.players', 'master']);

        // Verificar que la sala haya terminado
        if ($room->status !== Room::STATUS_FINISHED) {
            return redirect()->route('rooms.show', ['code' => $code]);
        }

        $stats = $this->roomService->getRoomStats($room);

        return view('rooms.results', compact('room', 'stats'));
    }

    /**
     * API: Iniciar la partida (solo master).
     */
    public function apiStart(Request $request, string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        // Verificar que el usuario sea el master
        if (!Auth::check() || Auth::id() !== $room->master_id) {
            return response()->json([
                'success' => false,
                'message' => 'Solo el master puede iniciar la partida',
            ], 403);
        }

        try {
            $this->roomService->startGame($room);

            // Refrescar sala para obtener último estado
            $room = $room->fresh();
            $room->load('match.players');

            // NOTA: El evento GameStartedEvent (genérico) se emite automáticamente
            // desde GameMatch::start() con el timing metadata correcto.
            // No necesitamos emitir nada aquí manualmente.

            return response()->json([
                'success' => true,
                'message' => 'Partida iniciada',
                'data' => [
                    'status' => $room->status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API: Obtener estadísticas de la sala.
     */
    public function apiStats(string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        $stats = $this->roomService->getRoomStats($room);
        $canStart = $this->roomService->canStartGame($room);

        return response()->json([
            'success' => true,
            'data' => array_merge($stats, [
                'can_start' => $canStart['can_start'],
                'can_start_reason' => $canStart['reason'],
                'status' => $room->status,
            ]),
        ]);
    }

    /**
     * API: Cerrar sala (solo master).
     */
    public function apiClose(Request $request, string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        // Verificar que el usuario sea el master
        if (!Auth::check() || Auth::id() !== $room->master_id) {
            return response()->json([
                'success' => false,
                'message' => 'Solo el master puede cerrar la sala',
            ], 403);
        }

        try {
            $this->roomService->closeRoom($room);

            // IMPORTANTE: Limpiar todas las sesiones cuando se cierra la sala
            // Esto asegura que los usuarios no tengan sesiones colgadas
            $this->playerSessionService->clearAllSessions();

            // Desconectar todos los jugadores de esta sala
            if ($room->match) {
                $room->match->players()->update([
                    'is_connected' => false,
                    'last_ping' => now(),
                ]);
            }

            \Log::info("Room closed and sessions cleared", [
                'code' => $code,
                'master_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sala cerrada',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API: Notificar que el jugador está conectado (starting phase).
     */
    public function apiPlayerConnected(Request $request, string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        try {
            // Obtener player_id del request
            $playerId = $request->input('player_id');

            if (!$playerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'player_id requerido',
                ], 400);
            }

            // Verificar fase actual
            $gameState = $room->match->game_state ?? [];
            $currentPhase = $gameState['phase'] ?? null;

            if ($currentPhase !== 'starting') {
                // No estamos en starting phase, no hacer nada
                return response()->json([
                    'success' => true,
                    'message' => 'Not in starting phase, skipping',
                    'data' => [
                        'current_phase' => $currentPhase
                    ]
                ]);
            }

            \Log::info("📱 Player connected notification received", [
                'room_code' => $code,
                'player_id' => $playerId,
                'phase' => $currentPhase
            ]);

            // Usar Cache para trackear jugadores conectados (funciona con file driver)
            $cacheKey = "room:{$code}:starting:connected_players";

            // Obtener lista actual de jugadores conectados
            $connectedPlayers = \Illuminate\Support\Facades\Cache::get($cacheKey, []);

            // Agregar jugador si no está ya (array evita duplicados)
            if (!in_array($playerId, $connectedPlayers)) {
                $connectedPlayers[] = $playerId;
                \Illuminate\Support\Facades\Cache::put($cacheKey, $connectedPlayers, now()->addMinutes(5));
            }

            // Contar cuántos jugadores han notificado
            $connectedCount = count($connectedPlayers);
            $totalPlayers = $room->match->players()->where('is_connected', true)->count();

            \Log::info("📊 Starting phase connection tracking", [
                'room_code' => $code,
                'connected_count' => $connectedCount,
                'total_players' => $totalPlayers,
                'connected_player_ids' => $connectedPlayers
            ]);

            // Emitir evento WebSocket para actualizar a todos los clientes
            event(new \App\Events\Game\PlayerConnectedToGameEvent(
                $code,
                $connectedCount,
                $totalPlayers
            ));

            // Si todos los jugadores están conectados, iniciar transición
            if ($connectedCount >= $totalPlayers) {
                \Log::info("✅✅✅ All players connected - Starting transition ✅✅✅", [
                    'room_code' => $code,
                    'total_players' => $totalPlayers,
                    'connected_count' => $connectedCount
                ]);

                // Llamar al engine para que emita GameStartedEvent con countdown
                $engine = $room->game->getEngine();
                $engine->transitionFromStarting($room->match);
            }

            return response()->json([
                'success' => true,
                'message' => 'Player connected notification received',
                'data' => [
                    'connected_count' => $connectedCount,
                    'total_players' => $totalPlayers,
                    'waiting_for' => $totalPlayers - $connectedCount,
                    'all_connected' => $connectedCount >= $totalPlayers
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error("Error processing player connected notification", [
                'room_code' => $code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Abandonar sala (jugador sale de la sala).
     */
    public function apiLeave(string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        try {
            // Buscar al jugador actual
            $player = null;
            if (Auth::check()) {
                $player = $room->match->players()->where('user_id', Auth::id())->first();
            } elseif ($this->playerSessionService->hasGuestSession()) {
                $guestData = $this->playerSessionService->getGuestData();
                $player = $room->match->players()->where('session_id', $guestData['session_id'])->first();
            }

            if ($player) {
                // Marcar como desconectado
                $player->update([
                    'is_connected' => false,
                    'last_ping' => now(),
                ]);

                // Emitir evento de jugador que salió
                $totalPlayers = $room->match->players()->where('is_connected', true)->count();
                event(new PlayerLeftEvent($room, $player, $totalPlayers));

                \Log::info("Player left room", [
                    'code' => $code,
                    'player_id' => $player->id,
                    'player_name' => $player->name,
                ]);
            }

            // Limpiar sesiones
            $this->playerSessionService->clearAllSessions();

            return response()->json([
                'success' => true,
                'message' => 'Has salido de la sala',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}


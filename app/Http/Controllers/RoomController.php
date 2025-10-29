<?php

namespace App\Http\Controllers;

use App\Events\PlayerJoinedEvent;
use App\Events\PlayerLeftEvent;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
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

            // IMPORTANTE: Verificar si el usuario invitado ya está autenticado
            // Solo autenticar si NO está autenticado (evita desconexiones del Presence Channel)
            if (!Auth::check()) {
                // No está autenticado, buscar o crear User y autenticar
                $guestUser = User::firstOrCreate(
                    ['name' => $guestData['name'], 'role' => 'guest'],
                    [
                        'email' => null,
                        'password' => null,
                        'guest_expires_at' => now()->addHours(24),
                    ]
                );

                Auth::login($guestUser);

                \Log::info("Lobby - guest user authenticated", [
                    'user_id' => $guestUser->id,
                    'name' => $guestUser->name,
                    'code' => $code,
                ]);
            } else {
                \Log::info("Lobby - guest user already authenticated, skipping login", [
                    'user_id' => Auth::id(),
                    'name' => Auth::user()->name,
                    'code' => $code,
                ]);
            }

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

        // Crear usuario temporal invitado y autenticarlo
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

            // Crear un User temporal con rol 'guest'
            $guestUser = User::create([
                'name' => $validated['player_name'],
                'email' => null, // Los invitados no tienen email
                'password' => null, // Los invitados no tienen password
                'role' => 'guest',
                'guest_expires_at' => now()->addHours(24), // Expira en 24 horas
            ]);

            // Autenticar automáticamente al usuario invitado
            Auth::login($guestUser);

            // Ahora sí, crear la nueva sesión limpia
            $this->playerSessionService->createGuestSession($validated['player_name']);

            \Log::info("Guest user created and authenticated", [
                'user_id' => $guestUser->id,
                'name' => $validated['player_name'],
                'room' => $code,
                'expires_at' => $guestUser->guest_expires_at,
            ]);

            return redirect()->route('rooms.lobby', ['code' => $code])
                ->with('success', '¡Bienvenido ' . $validated['player_name'] . '!');
        } catch (\Exception $e) {
            \Log::error("Error creating guest user", [
                'error' => $e->getMessage(),
                'code' => $code,
            ]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Router de sala - Redirige según el estado de la sala.
     *
     * SEPARACIÓN DE RESPONSABILIDADES:
     * - WAITING → Lobby (rooms.lobby)
     * - ACTIVE → Transition (showTransition)
     * - PLAYING → Game (play.show) ← PlayController
     * - FINISHED → Results (rooms.results)
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

        // Si la sala está en transición (ACTIVE), mostrar vista de countdown
        if ($room->status === Room::STATUS_ACTIVE) {
            return $this->showTransition($code, $room);
        }

        // Si la sala está jugando (PLAYING), redirigir a PlayController
        if ($room->status === Room::STATUS_PLAYING) {
            return redirect()->route('play.show', ['code' => $code]);
        }

        // Si la sala terminó, mostrar resultados
        if ($room->status === Room::STATUS_FINISHED) {
            return redirect()->route('rooms.results', ['code' => $code]);
        }

        // Estado no reconocido, redirigir al lobby
        return redirect()->route('rooms.lobby', ['code' => $code]);
    }

    /**
     * Mostrar vista de transición (estado ACTIVE).
     *
     * Esta vista se muestra cuando el juego ha sido iniciado desde el lobby,
     * pero el engine aún no ha sido cargado. Aquí se:
     * 1. Verifica que todos los jugadores estén conectados (Presence Channel)
     * 2. Emite el countdown cuando todos están listos
     * 3. Inicializa el engine después del countdown
     *
     * @param string $code Código de la sala
     * @param Room $room Modelo de la sala
     */
    private function showTransition(string $code, Room $room)
    {
        // Obtener lista de jugadores esperados (del evento game.started)
        $expectedPlayers = $room->match->players()
            ->where('is_connected', true)
            ->get(['id', 'name', 'user_id'])
            ->map(function ($player) {
                return [
                    'id' => $player->id,
                    'name' => $player->name,
                    'user_id' => $player->user_id,
                ];
            })->toArray();

        $totalPlayers = count($expectedPlayers);

        \Log::info("Transition view - Waiting for all players to connect", [
            'room_code' => $code,
            'total_players' => $totalPlayers,
        ]);

        return view('rooms.transition', [
            'room' => $room,
            'code' => $code,
            'expectedPlayers' => $expectedPlayers,
            'totalPlayers' => $totalPlayers,
            'gameName' => $room->game->name,
        ]);
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
     * API: Marcar que todos los jugadores están listos en el room.
     *
     * Se llama desde el frontend cuando el Presence Channel confirma
     * que todos los jugadores del evento game.started están conectados.
     */
    public function apiReady(Request $request, string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        // Verificar que la sala esté en estado 'active' (transición)
        if ($room->status !== Room::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'La sala no está en estado de transición',
            ], 400);
        }

        try {
            // 1. Emitir evento de countdown
            event(new \App\Events\Game\GameCountdownEvent($room, 3));

            \Log::info("✅ [RoomController] All players ready - Countdown started", [
                'room_code' => $code,
                'players' => $room->match->players()->count(),
            ]);

            // NOTA: GameStartedEvent ya NO se emite aquí
            // Se emitirá desde PlayController::apiDomLoaded() cuando TODOS los jugadores
            // hayan cargado completamente su DOM y estén listos para recibir eventos.
            // Esto garantiza sincronización perfecta sin eventos perdidos.

            return response()->json([
                'success' => true,
                'message' => 'Countdown iniciado',
                'countdown_seconds' => 3,
            ]);
        } catch (\Exception $e) {
            \Log::error("Error marking room as ready: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar countdown',
            ], 500);
        }
    }

    /**
     * API: Inicializar el engine del juego (llamado después del countdown).
     *
     * IMPORTANTE: Protección contra Race Conditions
     * - TODOS los clientes llaman a este endpoint cuando termina el countdown
     * - Solo el PRIMERO que llega ejecuta initializeEngine()
     * - Los demás reciben 200 OK con already_processing: true
     * - Todos se sincronizan con el evento GameInitializedEvent
     */
    public function apiInitializeEngine(Request $request, string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        $match = $room->match;

        // Verificar que la sala esté en estado 'active'
        if ($room->status !== Room::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'La sala no está lista para inicializar engine',
            ], 400);
        }

        // Intentar adquirir lock (solo el primer cliente lo consigue)
        if (!$match->acquireRoundLock()) {
            \Log::info('⏸️  [Transition] Lock already held, another client is initializing engine', [
                'room_code' => $code,
                'match_id' => $match->id,
            ]);

            return response()->json([
                'success' => true,
                'already_processing' => true,
                'message' => 'Another client is initializing the engine, you will receive GameInitializedEvent shortly',
            ], 200); // 200 OK para evitar errores en consola
        }

        // Lock adquirido - proceder a marcar sala como 'playing'
        try {
            \Log::info('🔒 [Transition] Lock acquired, preparing room for game', [
                'room_code' => $code,
                'match_id' => $match->id,
                'game' => $room->game->slug,
            ]);

            // IMPORTANTE: Ya NO llamamos a initializeEngine() aquí
            // La inicialización completa del engine se hará desde PlayController::apiDomLoaded()
            // cuando TODOS los jugadores tengan su DOM cargado.
            //
            // Aquí solo:
            // 1. Cambiamos el status de la sala a 'playing'
            // 2. Seteamos phase = 'starting' en game_state
            // 3. Emitimos GameInitializedEvent para que los clientes redirijan a /play

            // Actualizar status de sala
            $room->update(['status' => Room::STATUS_PLAYING]);

            // Setear game_state mínimo con phase = 'starting'
            $match->game_state = array_merge($match->game_state ?? [], [
                'phase' => 'starting',
                'transition_completed_at' => now()->toDateTimeString(),
            ]);
            $match->save();

            // Emitir evento para redirigir clientes a /play
            event(new \App\Events\Game\GameInitializedEvent($match, $match->game_state));

            \Log::info("✅ [Transition] Room prepared for game (engine will initialize when all DOM loaded)", [
                'room_code' => $code,
                'game' => $room->game->slug,
                'phase' => 'starting',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Room prepared, waiting for all players DOM loaded',
            ]);
        } catch (\Exception $e) {
            \Log::error("❌ [Transition] Error preparing room: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Error al preparar sala: ' . $e->getMessage(),
            ], 500);
        } finally {
            // SIEMPRE liberar el lock
            $match->releaseRoundLock();
            \Log::info('🔓 [Transition] Lock released', [
                'room_code' => $code,
                'match_id' => $match->id,
            ]);
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

    /**
     * Verificar si todos los jugadores están conectados
     *
     * El frontend llama a este endpoint cuando hay cambios en el Presence Channel
     * (alguien se conecta o desconecta). El backend verifica si todos están
     * conectados y dispara el evento correspondiente.
     */
    public function checkAllPlayersConnected(Request $request, string $code)
    {
        $room = Room::where('code', $code)->firstOrFail();
        $match = $room->match;

        if (!$match) {
            return response()->json([
                'success' => false,
                'error' => 'No match found'
            ], 404);
        }

        // Obtener número de jugadores conectados desde el request
        $connectedCount = $request->input('connected_count', 0);

        // Jugadores mínimos para empezar (no el máximo)
        $minPlayers = $room->game->min_players;
        $totalPlayers = $match->players()->count();

        \Log::info('🔍 [Presence Check]', [
            'room_code' => $code,
            'connected' => $connectedCount,
            'min_players' => $minPlayers,
            'total_players' => $totalPlayers,
        ]);

        // Si alcanzamos el mínimo de jugadores conectados, disparar evento
        if ($connectedCount > 0 && $connectedCount >= $minPlayers) {
            \Log::info('✅ [Presence] Minimum players connected! Broadcasting event...', [
                'room_code' => $code,
                'connected' => $connectedCount,
                'min_required' => $minPlayers,
            ]);

            // Disparar evento broadcast
            event(new \App\Events\AllPlayersConnectedEvent($room, $connectedCount, $minPlayers));
        }

        return response()->json([
            'success' => true,
            'connected' => $connectedCount,
            'min_players' => $minPlayers,
            'total' => $minPlayers, // Para compatibilidad con frontend
            'all_connected' => $connectedCount >= $minPlayers,
        ]);
    }

    /**
     * Obtener el estado actual del juego de una sala.
     *
     * @param string $code Código de la sala
     * @return \Illuminate\Http\JsonResponse
     */
    public function apiGetState(string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        // Si no hay match, retornar estado básico
        if (!$room->match) {
            return response()->json([
                'success' => true,
                'room_code' => $code,
                'status' => $room->status,
                'game_state' => null,
            ]);
        }

        $match = $room->match;

        // Cargar jugadores con sus datos
        $players = $match->players->map(function ($player) {
            return [
                'id' => $player->id,
                'name' => $player->name,
                'user_id' => $player->user_id,
            ];
        });

        return response()->json([
            'success' => true,
            'room_code' => $code,
            'status' => $room->status,
            'game_state' => $match->game_state,
            'players' => $players,
        ]);
    }

    /**
     * Obtener información del jugador actual en una sala.
     *
     * @param string $code Código de la sala
     * @return \Illuminate\Http\JsonResponse
     */
    public function apiGetPlayerInfo(string $code)
    {
        $code = strtoupper($code);
        
        // Buscar sala usando RoomService
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        // Verificar que la sala tenga un match
        if (!$room->match) {
            return response()->json([
                'success' => false,
                'message' => 'La sala no tiene un juego activo',
            ], 400);
        }

        // Obtener el jugador actual
        $userId = Auth::id();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'No estás autenticado',
            ], 401);
        }

        $match = $room->match;
        $player = $match->players->firstWhere('user_id', $userId);

        if (!$player) {
            return response()->json([
                'success' => false,
                'message' => 'No estás registrado en esta sala',
            ], 403);
        }

        // Retornar información del jugador
        return response()->json([
            'success' => true,
            'player_id' => $player->id,
            'player_name' => $player->name,
            'user_id' => $player->user_id,
        ]);
    }
}


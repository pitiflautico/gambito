<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Room;
use App\Services\Core\RoomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoomController extends Controller
{
    /**
     * Room service.
     */
    protected RoomService $roomService;

    public function __construct(RoomService $roomService)
    {
        $this->roomService = $roomService;

        // Solo usuarios autenticados pueden crear salas
        $this->middleware('auth')->only(['create', 'store']);
    }

    /**
     * Mostrar formulario para crear una sala.
     */
    public function create()
    {
        // Obtener juegos activos
        $games = Game::active()->get();

        return view('rooms.create', compact('games'));
    }

    /**
     * Crear una nueva sala.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'game_id' => 'required|exists:games,id',
            'max_players' => 'nullable|integer|min:1|max:100',
            'private' => 'nullable|boolean',
        ]);

        $game = Game::findOrFail($validated['game_id']);

        // Validar que el juego esté activo
        if (!$game->is_active) {
            return back()->withErrors(['game_id' => 'Este juego no está disponible actualmente.']);
        }

        // Preparar settings
        $settings = [];
        if (isset($validated['max_players'])) {
            $settings['max_players'] = $validated['max_players'];
        }
        if (isset($validated['private'])) {
            $settings['private'] = $validated['private'];
        }

        try {
            // Crear sala
            $room = $this->roomService->createRoom($game, Auth::user(), $settings);

            // Crear partida asociada
            $match = GameMatch::create([
                'room_id' => $room->id,
                'game_state' => [],
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

        // Verificar que la sala no haya terminado
        if ($room->status === Room::STATUS_FINISHED) {
            return redirect()->route('home')
                ->with('error', 'Esta sala ya ha terminado.');
        }

        // Si la sala ya está jugando, redirigir a la sala activa
        if ($room->status === Room::STATUS_PLAYING) {
            return redirect()->route('rooms.show', ['code' => $code]);
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

        return view('rooms.lobby', compact('room', 'stats', 'inviteUrl', 'qrCodeUrl', 'canStart', 'isMaster'));
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

        // Si la sala está esperando, redirigir al lobby
        if ($room->status === Room::STATUS_WAITING) {
            return redirect()->route('rooms.lobby', ['code' => $code]);
        }

        // Si la sala terminó, mostrar resultados
        if ($room->status === Room::STATUS_FINISHED) {
            return redirect()->route('rooms.results', ['code' => $code]);
        }

        // Cargar vista específica del juego
        return view('rooms.show', compact('room'));
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

            return response()->json([
                'success' => true,
                'message' => 'Partida iniciada',
                'data' => [
                    'status' => $room->fresh()->status,
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
}


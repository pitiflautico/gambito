<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Room;
use App\Repositories\RoomRepository;
use App\Services\Modules\TeamsSystem\TeamsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TeamController - API REST para gestión de equipos
 *
 * Maneja las operaciones CRUD de equipos en el lobby antes de iniciar la partida.
 */
class TeamController extends Controller
{
    protected RoomRepository $roomRepository;

    public function __construct(RoomRepository $roomRepository)
    {
        $this->roomRepository = $roomRepository;
    }
    /**
     * Habilitar modo equipos
     */
    public function enable(Request $request, string $roomCode): JsonResponse
    {
        $request->validate([
            'mode' => 'required|in:team_turns,all_teams,sequential_within_team',
            'num_teams' => 'required|integer|min:2|max:8'
        ]);

        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);

            // Solo el master puede configurar equipos
            if (!$this->isMaster($request, $room)) {
                return response()->json(['error' => 'Solo el organizador puede configurar equipos'], 403);
            }

            $match = $room->match;

            if (!$match) {
                return response()->json(['error' => 'No hay partida activa'], 404);
            }

            if ($match->started_at) {
                return response()->json(['error' => 'No se puede cambiar la configuración después de iniciar'], 400);
            }

            $teamsManager = new TeamsManager($match);
            $teamsManager->enableTeams($request->mode, $request->num_teams);

            // Broadcast evento
            event(new \App\Events\Lobby\TeamsConfigUpdatedEvent($room));

            return response()->json([
                'success' => true,
                'teams' => $teamsManager->getTeams()
            ]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error habilitando equipos: {$e->getMessage()}");
            return response()->json(['error' => 'Error al habilitar equipos'], 500);
        }
    }

    /**
     * Deshabilitar modo equipos
     */
    public function disable(Request $request, string $roomCode): JsonResponse
    {
        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);

            if (!$this->isMaster($request, $room)) {
                return response()->json(['error' => 'Solo el organizador puede configurar equipos'], 403);
            }

            $match = $room->match;

            if (!$match) {
                return response()->json(['error' => 'No hay partida activa'], 404);
            }

            $teamsManager = new TeamsManager($match);
            $teamsManager->disableTeams();

            // Broadcast evento
            event(new \App\Events\Lobby\TeamsConfigUpdatedEvent($room));

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error deshabilitando equipos: {$e->getMessage()}");
            return response()->json(['error' => 'Error al deshabilitar equipos'], 500);
        }
    }

    /**
     * Obtener configuración de equipos
     */
    public function index(string $roomCode): JsonResponse
    {
        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);
            $match = $room->match; // Cambio: match es singular, no plural

            if (!$match) {
                return response()->json([
                    'success' => true,
                    'enabled' => false,
                    'teams' => []
                ]);
            }

            $teamsManager = new TeamsManager($match);

            return response()->json([
                'success' => true,
                'enabled' => $teamsManager->isEnabled(),
                'mode' => $teamsManager->getMode(),
                'allow_self_selection' => $teamsManager->getAllowSelfSelection(),
                'teams' => $teamsManager->getTeams()
            ]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error obteniendo equipos: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'error' => 'Error al obtener equipos'
            ], 500);
        }
    }

    /**
     * Crear un nuevo equipo
     */
    public function store(Request $request, string $roomCode): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|regex:/^#[0-9A-F]{6}$/i'
        ]);

        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);

            if (!$this->isMaster($request, $room)) {
                return response()->json(['error' => 'Solo el organizador puede crear equipos'], 403);
            }

            $match = $room->match;

            if (!$match) {
                return response()->json(['error' => 'No hay partida activa'], 404);
            }

            $teamsManager = new TeamsManager($match);

            if (!$teamsManager->isEnabled()) {
                return response()->json(['error' => 'El modo equipos no está activado'], 400);
            }

            $team = $teamsManager->createTeam($request->name, $request->color);

            // Broadcast evento
            event(new \App\Events\Lobby\TeamCreatedEvent($room, $team));

            return response()->json([
                'success' => true,
                'team' => $team
            ]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error creando equipo: {$e->getMessage()}");
            return response()->json(['error' => 'Error al crear equipo'], 500);
        }
    }

    /**
     * Eliminar un equipo
     */
    public function destroy(Request $request, string $roomCode, string $teamId): JsonResponse
    {
        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);

            if (!$this->isMaster($request, $room)) {
                return response()->json(['error' => 'Solo el organizador puede eliminar equipos'], 403);
            }

            $match = $room->match;

            if (!$match) {
                return response()->json(['error' => 'No hay partida activa'], 404);
            }

            $teamsManager = new TeamsManager($match);
            $deleted = $teamsManager->deleteTeam($teamId);

            if (!$deleted) {
                return response()->json(['error' => 'Equipo no encontrado'], 404);
            }

            // Broadcast evento
            event(new \App\Events\Lobby\TeamDeletedEvent($room, $teamId));

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error eliminando equipo: {$e->getMessage()}");
            return response()->json(['error' => 'Error al eliminar equipo'], 500);
        }
    }

    /**
     * Asignar jugador a un equipo
     */
    public function assignPlayer(Request $request, string $roomCode): JsonResponse
    {
        $request->validate([
            'player_id' => 'required|integer',
            'team_id' => 'required|string'
        ]);

        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);
            $match = $room->match;

            if (!$match) {
                return response()->json(['error' => 'No hay partida activa'], 404);
            }

            $teamsManager = new TeamsManager($match);

            if (!$teamsManager->isEnabled()) {
                return response()->json(['error' => 'El modo equipos no está activado'], 400);
            }

            // Verificar permisos
            $isMaster = $this->isMaster($request, $room);
            $isOwnPlayer = auth()->check() && auth()->id() == $request->player_id;
            $allowSelfSelection = $teamsManager->getAllowSelfSelection();

            if (!$isMaster && !($isOwnPlayer && $allowSelfSelection)) {
                return response()->json(['error' => 'No tienes permiso para mover este jugador'], 403);
            }

            $success = $teamsManager->assignPlayerToTeam($request->player_id, $request->team_id);

            if (!$success) {
                return response()->json(['error' => 'No se pudo asignar el jugador (equipo lleno o no encontrado)'], 400);
            }

            // Broadcast evento
            event(new \App\Events\Lobby\PlayerMovedToTeamEvent($room, $request->player_id, $request->team_id));

            return response()->json([
                'success' => true,
                'teams' => $teamsManager->getTeams()
            ]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error asignando jugador: {$e->getMessage()}");
            return response()->json(['error' => 'Error al asignar jugador'], 500);
        }
    }

    /**
     * Remover jugador de su equipo
     */
    public function removePlayer(Request $request, string $roomCode, int $playerId): JsonResponse
    {
        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);

            if (!$this->isMaster($request, $room)) {
                return response()->json(['error' => 'Solo el organizador puede remover jugadores'], 403);
            }

            $match = $room->match;

            if (!$match) {
                return response()->json(['error' => 'No hay partida activa'], 404);
            }

            $teamsManager = new TeamsManager($match);
            $success = $teamsManager->removePlayerFromTeam($playerId);

            if (!$success) {
                return response()->json(['error' => 'Jugador no encontrado en ningún equipo'], 404);
            }

            // Broadcast evento
            event(new \App\Events\Lobby\PlayerRemovedFromTeamEvent($room, $playerId));

            return response()->json([
                'success' => true,
                'teams' => $teamsManager->getTeams()
            ]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error removiendo jugador: {$e->getMessage()}");
            return response()->json(['error' => 'Error al remover jugador'], 500);
        }
    }

    /**
     * Balancear equipos automáticamente
     */
    public function balance(Request $request, string $roomCode): JsonResponse
    {
        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);

            if (!$this->isMaster($request, $room)) {
                return response()->json(['error' => 'Solo el organizador puede balancear equipos'], 403);
            }

            $match = $room->match;

            if (!$match) {
                return response()->json(['error' => 'No hay partida activa'], 404);
            }

            $teamsManager = new TeamsManager($match);

            if (!$teamsManager->isEnabled()) {
                return response()->json(['error' => 'El modo equipos no está activado'], 400);
            }

            // Obtener todos los jugadores de la partida
            $playerIds = $match->players()->pluck('id')->toArray();

            $teamsManager->balanceTeams($playerIds);

            // Broadcast evento
            event(new \App\Events\Lobby\TeamsBalancedEvent($room));

            return response()->json([
                'success' => true,
                'teams' => $teamsManager->getTeams()
            ]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error balanceando equipos: {$e->getMessage()}");
            return response()->json(['error' => 'Error al balancear equipos'], 500);
        }
    }

    /**
     * Actualizar configuración de autoselección
     */
    public function updateSelfSelection(Request $request, string $roomCode): JsonResponse
    {
        $request->validate([
            'allow_self_selection' => 'required|boolean'
        ]);

        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);

            if (!$this->isMaster($request, $room)) {
                return response()->json(['error' => 'Solo el organizador puede cambiar esta configuración'], 403);
            }

            $match = $room->match;

            if (!$match) {
                return response()->json(['error' => 'No hay partida activa'], 404);
            }

            $teamsManager = new TeamsManager($match);
            $teamsManager->setAllowSelfSelection($request->allow_self_selection);

            // Broadcast evento
            event(new \App\Events\Lobby\TeamsConfigUpdatedEvent($room));

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error actualizando configuración: {$e->getMessage()}");
            return response()->json(['error' => 'Error al actualizar configuración'], 500);
        }
    }

    /**
     * Validar si los equipos están listos para iniciar
     */
    public function validate(string $roomCode): JsonResponse
    {
        try {
            $room = $this->roomRepository->findByCodeOrFail($roomCode);
            $match = $room->match;

            if (!$match) {
                return response()->json(['error' => 'No hay partida activa'], 404);
            }

            $teamsManager = new TeamsManager($match);

            if (!$teamsManager->isEnabled()) {
                return response()->json([
                    'valid' => true,
                    'errors' => []
                ]);
            }

            $errors = $teamsManager->validateTeamsForStart();

            return response()->json([
                'valid' => empty($errors),
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            Log::error("[TeamController] Error validando equipos: {$e->getMessage()}");
            return response()->json(['error' => 'Error al validar equipos'], 500);
        }
    }

    /**
     * Verificar si el usuario es el master de la sala
     */
    protected function isMaster(Request $request, Room $room): bool
    {
        return auth()->check() && auth()->id() === $room->master_id;
    }
}

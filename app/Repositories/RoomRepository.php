<?php

namespace App\Repositories;

use App\Models\Room;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class RoomRepository
{
    /**
     * Encontrar sala por código
     */
    public function findByCode(string $code): ?Room
    {
        return Room::where('code', strtoupper($code))->first();
    }

    /**
     * Encontrar sala por código (lanza excepción si no existe)
     */
    public function findByCodeOrFail(string $code): Room
    {
        return Room::where('code', strtoupper($code))->firstOrFail();
    }

    /**
     * Encontrar sala por ID
     */
    public function findById(int $id): ?Room
    {
        return Room::find($id);
    }

    /**
     * Crear una nueva sala
     */
    public function create(array $data): Room
    {
        return Room::create($data);
    }

    /**
     * Actualizar sala
     */
    public function update(Room $room, array $data): bool
    {
        return $room->update($data);
    }

    /**
     * Obtener salas en espera
     */
    public function getWaitingRooms(int $limit = 10): Collection
    {
        return Room::waiting()
            ->with(['game', 'master'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Obtener salas activas (jugando)
     */
    public function getActiveRooms(int $limit = 10): Collection
    {
        return Room::playing()
            ->with(['game', 'master'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Obtener salas de un usuario específico
     */
    public function getUserRooms(User $user): Collection
    {
        return Room::where('master_id', $user->id)
            ->with(['game', 'match'])
            ->latest()
            ->get();
    }

    /**
     * Verificar si un código existe
     */
    public function codeExists(string $code): bool
    {
        return Room::where('code', strtoupper($code))->exists();
    }

    /**
     * Cargar relaciones de una sala
     */
    public function loadRelations(Room $room, array $relations = ['game', 'master', 'match.players']): Room
    {
        return $room->load($relations);
    }

    /**
     * Cambiar estado de sala
     */
    public function changeStatus(Room $room, string $status): bool
    {
        return $room->update(['status' => $status]);
    }

    /**
     * Iniciar partida (cambiar a playing)
     */
    public function startMatch(Room $room): bool
    {
        return $this->changeStatus($room, Room::STATUS_PLAYING);
    }

    /**
     * Finalizar partida (cambiar a finished)
     */
    public function finishMatch(Room $room): bool
    {
        return $this->changeStatus($room, Room::STATUS_FINISHED);
    }

    /**
     * Eliminar sala
     */
    public function delete(Room $room): bool
    {
        return $room->delete();
    }

    /**
     * Obtener estadísticas de una sala
     */
    public function getStats(Room $room): array
    {
        $players = 0;
        $connectedPlayers = 0;

        if ($room->match) {
            $players = $room->match->players()->count();
            $connectedPlayers = $room->match->players()->where('is_connected', true)->count();
        }

        return [
            'players' => $players,
            'connected_players' => $connectedPlayers,
            'status' => $room->status,
            'can_start' => $room->canStart(),
        ];
    }

    /**
     * Obtener salas antiguas para limpiar (más de X horas)
     */
    public function getOldRooms(int $hours = 24): Collection
    {
        return Room::where('updated_at', '<', now()->subHours($hours))
            ->whereIn('status', [Room::STATUS_WAITING, Room::STATUS_FINISHED])
            ->get();
    }
}

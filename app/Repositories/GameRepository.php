<?php

namespace App\Repositories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Collection;

class GameRepository
{
    /**
     * Obtener todos los juegos activos
     */
    public function getActiveGames(): Collection
    {
        return Game::active()->orderBy('name')->get();
    }

    /**
     * Encontrar juego por ID
     */
    public function findById(int $id): ?Game
    {
        return Game::find($id);
    }

    /**
     * Encontrar juego por ID (lanza excepción si no existe)
     */
    public function findByIdOrFail(int $id): Game
    {
        return Game::findOrFail($id);
    }

    /**
     * Encontrar juego por slug
     */
    public function findBySlug(string $slug): ?Game
    {
        return Game::where('slug', $slug)->first();
    }

    /**
     * Encontrar juego por slug (lanza excepción si no existe)
     */
    public function findBySlugOrFail(string $slug): Game
    {
        return Game::where('slug', $slug)->firstOrFail();
    }

    /**
     * Verificar si un juego está activo
     */
    public function isActive(int $gameId): bool
    {
        return Game::where('id', $gameId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Crear un nuevo juego
     */
    public function create(array $data): Game
    {
        return Game::create($data);
    }

    /**
     * Actualizar juego
     */
    public function update(Game $game, array $data): bool
    {
        return $game->update($data);
    }

    /**
     * Activar juego
     */
    public function activate(Game $game): bool
    {
        return $game->update(['is_active' => true]);
    }

    /**
     * Desactivar juego
     */
    public function deactivate(Game $game): bool
    {
        return $game->update(['is_active' => false]);
    }

    /**
     * Eliminar juego
     */
    public function delete(Game $game): bool
    {
        return $game->delete();
    }

    /**
     * Obtener todos los juegos (activos e inactivos)
     */
    public function getAll(): Collection
    {
        return Game::orderBy('name')->get();
    }

    /**
     * Verificar si un slug existe
     */
    public function slugExists(string $slug): bool
    {
        return Game::where('slug', $slug)->exists();
    }

    /**
     * Obtener juegos por tipo
     */
    public function getByType(string $type): Collection
    {
        return Game::active()
            ->where('type', $type)
            ->orderBy('name')
            ->get();
    }

    /**
     * Buscar juegos (por nombre o descripción)
     */
    public function search(string $query): Collection
    {
        return Game::active()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Obtener estadísticas de un juego
     */
    public function getStats(Game $game): array
    {
        $totalRooms = $game->rooms()->count();
        $activeRooms = $game->rooms()->playing()->count();
        $finishedRooms = $game->rooms()->finished()->count();

        return [
            'total_rooms' => $totalRooms,
            'active_rooms' => $activeRooms,
            'finished_rooms' => $finishedRooms,
            'popularity_score' => $totalRooms, // Puede ser más complejo
        ];
    }
}

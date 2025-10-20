<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\Core\GameRegistry;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * Game registry service.
     */
    protected GameRegistry $registry;

    public function __construct(GameRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Listar todos los juegos disponibles.
     *
     * Muestra una página con todos los juegos activos.
     */
    public function index()
    {
        $games = $this->registry->getActiveGames();

        return view('games.index', compact('games'));
    }

    /**
     * Mostrar detalles de un juego específico.
     *
     * @param string $slug Slug del juego
     */
    public function show(string $slug)
    {
        $game = Game::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return view('games.show', compact('game'));
    }

    /**
     * API: Obtener lista de juegos disponibles en JSON.
     */
    public function apiIndex()
    {
        $games = $this->registry->getActiveGames();

        return response()->json([
            'success' => true,
            'data' => $games->map(function ($game) {
                return [
                    'id' => $game->id,
                    'name' => $game->name,
                    'slug' => $game->slug,
                    'description' => $game->description,
                    'min_players' => $game->min_players,
                    'max_players' => $game->max_players,
                    'estimated_duration' => $game->estimated_duration,
                    'is_premium' => $game->is_premium,
                ];
            }),
        ]);
    }

    /**
     * API: Obtener configuración completa de un juego.
     *
     * @param string $slug Slug del juego
     */
    public function apiShow(string $slug)
    {
        $game = Game::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $game->id,
                'name' => $game->name,
                'slug' => $game->slug,
                'description' => $game->description,
                'config' => $game->config,
                'capabilities' => $game->capabilities,
                'min_players' => $game->min_players,
                'max_players' => $game->max_players,
                'estimated_duration' => $game->estimated_duration,
                'is_premium' => $game->is_premium,
            ],
        ]);
    }
}

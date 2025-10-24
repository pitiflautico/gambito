<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico emitido cuando un juego inicia.
 *
 * Se emite desde GameMatch::start() después de que el engine
 * haya inicializado el estado del juego.
 *
 * El frontend usa este evento para:
 * - Sincronizarse con el estado inicial del juego
 * - Mostrar la pantalla de juego activa
 * - Preparar la UI según la fase inicial
 */
class GameStartedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $gameSlug;
    public array $gameState;
    public int $totalPlayers;
    public array $players;

    public function __construct(
        GameMatch $match,
        array $gameState
    ) {
        $this->roomCode = $match->room->code;
        $this->gameSlug = $match->room->game->slug;
        $this->gameState = $gameState;

        // Información de jugadores
        $this->players = $match->players->map(function ($player) {
            return [
                'id' => $player->id,
                'name' => $player->name,
            ];
        })->toArray();

        $this->totalPlayers = count($this->players);
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.started';
    }

    public function broadcastWith(): array
    {
        return [
            'game_slug' => $this->gameSlug,
            'game_state' => $this->gameState,
            'total_players' => $this->totalPlayers,
            'players' => $this->players,
        ];
    }
}

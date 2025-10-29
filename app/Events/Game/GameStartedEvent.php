<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
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
 *
 * Timing Metadata:
 * El campo `timing` contiene información para el TimingModule del frontend:
 * - auto_next (bool): Si debe avanzar automáticamente a la primera ronda
 * - delay (int): Segundos a esperar antes de avanzar
 * - action (string): Acción a realizar (ej: 'start_first_round')
 * - message (string): Mensaje para mostrar en countdown (opcional)
 */
class GameStartedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $gameSlug;
    public array $gameState;
    public int $totalPlayers;
    public array $players;
    public ?array $timing;

    public function __construct(
        GameMatch $match,
        array $gameState,
        ?array $timing = null
    ) {
        $this->roomCode = $match->room->code;
        $this->gameSlug = $match->room->game->slug;
        $this->gameState = $gameState;
        $this->timing = $timing;

        // Información de jugadores
        $this->players = $match->players->map(function ($player) {
            return [
                'id' => $player->id,
                'name' => $player->name,
            ];
        })->toArray();

        $this->totalPlayers = count($this->players);

        \Log::info('🎮 [GameStartedEvent] Event created', [
            'room_code' => $this->roomCode,
            'game_slug' => $this->gameSlug,
            'total_players' => $this->totalPlayers,
            'has_timing' => $timing !== null,
            'timing' => $timing
        ]);
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.started';
    }

    public function broadcastWith(): array
    {
        $data = [
            'game_slug' => $this->gameSlug,
            'game_state' => $this->gameState,
            'total_players' => $this->totalPlayers,
            'players' => $this->players,
        ];

        // Añadir timing metadata si está presente
        if ($this->timing !== null) {
            $data['timing'] = $this->timing;
        }

        return $data;
    }
}

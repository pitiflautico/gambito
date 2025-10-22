<?php

namespace Games\Pictionary\Events;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se dispara cuando un jugador es eliminado de la ronda actual.
 *
 * Esto ocurre cuando el dibujante confirma que la respuesta del jugador
 * fue INCORRECTA. El jugador ya no puede participar en esta ronda.
 */
class PlayerEliminatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;
    public bool $gameResumes;

    /**
     * Create a new event instance.
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador eliminado
     */
    public function __construct(GameMatch $match, Player $player)
    {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->playerName = $player->name;
        $this->gameResumes = true; // El juego continúa después de eliminar
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('room.' . $this->roomCode),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'pictionary.player.eliminated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'game_resumes' => $this->gameResumes,
            'is_paused' => false,
            'message' => "❌ {$this->playerName} falló. Eliminado de esta ronda.",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}

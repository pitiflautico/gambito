<?php

namespace App\Events\Pictionary;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando un jugador reclama que sabe la respuesta.
 *
 * Este evento se broadcast a todos los jugadores en la sala,
 * especialmente al drawer para que pueda validar.
 */
class AnswerClaimedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;

    /**
     * Create a new event instance.
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que reclama saber la respuesta
     */
    public function __construct(
        GameMatch $match,
        Player $player
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->playerName = $player->name;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'pictionary.answer-claimed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
        ];
    }
}

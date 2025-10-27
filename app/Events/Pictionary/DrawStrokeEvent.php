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
 * Evento emitido cuando el drawer realiza un trazo en el canvas.
 *
 * Este evento se broadcast a todos los jugadores en la sala para que
 * puedan ver el dibujo en tiempo real.
 */
class DrawStrokeEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public array $stroke;

    /**
     * Create a new event instance.
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que está dibujando (drawer)
     * @param array $stroke Los datos del trazo (points, color, size)
     */
    public function __construct(
        GameMatch $match,
        Player $player,
        array $stroke
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->stroke = $stroke;
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
        return 'pictionary.draw-stroke';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'stroke' => $this->stroke,
        ];
    }
}

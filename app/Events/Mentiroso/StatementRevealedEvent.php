<?php

namespace App\Events\Mentiroso;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que revela la frase y su veracidad SOLO al orador
 *
 * Se envía por canal privado para que solo el orador sepa si la frase es verdadera o falsa.
 */
class StatementRevealedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public GameMatch $match;
    public Player $orador;
    public string $statement;
    public bool $isTrue;
    public int $roundNumber;

    public function __construct(GameMatch $match, Player $orador, string $statement, bool $isTrue, int $roundNumber)
    {
        $this->match = $match;
        $this->orador = $orador;
        $this->statement = $statement;
        $this->isTrue = $isTrue;
        $this->roundNumber = $roundNumber;
    }

    /**
     * Canal PRIVADO - Solo el orador recibe este evento
     */
    public function broadcastOn()
    {
        // Canal privado del jugador (orador)
        return new PrivateChannel('player.' . $this->orador->id);
    }

    /**
     * Nombre del evento
     */
    public function broadcastAs()
    {
        return 'statement.revealed';
    }

    /**
     * Datos que se envían al cliente
     */
    public function broadcastWith()
    {
        return [
            'match_id' => $this->match->id,
            'room_code' => $this->match->room->code,
            'statement' => $this->statement,
            'is_true' => $this->isTrue,
            'round_number' => $this->roundNumber,
            'orador_id' => $this->orador->id,
        ];
    }
}

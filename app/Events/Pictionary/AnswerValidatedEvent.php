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
 * Evento emitido cuando el drawer valida la respuesta de un jugador.
 *
 * Este evento se broadcast a todos los jugadores en la sala.
 */
class AnswerValidatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;
    public bool $isCorrect;
    public int $points;

    /**
     * Create a new event instance.
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador cuya respuesta fue validada
     * @param bool $isCorrect Si la respuesta fue correcta
     * @param int $points Puntos otorgados (0 si incorrecto)
     */
    public function __construct(
        GameMatch $match,
        Player $player,
        bool $isCorrect,
        int $points = 0
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->playerName = $player->name;
        $this->isCorrect = $isCorrect;
        $this->points = $points;
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
        return 'pictionary.answer-validated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'is_correct' => $this->isCorrect,
            'points' => $this->points,
        ];
    }
}

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
 * Evento emitido cuando un jugador adivina correctamente la palabra.
 *
 * Este evento se broadcast a todos los jugadores en la sala para
 * mostrar feedback visual y actualizar el feed de guesses.
 */
class CorrectGuessEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;
    public string $guess;
    public int $points;
    public int $totalScore;

    /**
     * Create a new event instance.
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que adivinó correctamente
     * @param string $guess La palabra que adivinó
     * @param int $points Los puntos ganados por esta respuesta correcta
     * @param int $totalScore El puntaje total del jugador después de esta respuesta
     */
    public function __construct(
        GameMatch $match,
        Player $player,
        string $guess,
        int $points,
        int $totalScore
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->playerName = $player->name;
        $this->guess = $guess;
        $this->points = $points;
        $this->totalScore = $totalScore;
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
        return 'pictionary.correct-guess';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'guess' => $this->guess,
            'points' => $this->points,
            'total_score' => $this->totalScore,
        ];
    }
}

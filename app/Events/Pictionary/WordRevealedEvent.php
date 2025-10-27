<?php

namespace App\Events\Pictionary;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento privado emitido al drawer para revelarle la palabra que debe dibujar.
 *
 * Este evento SOLO se envía al drawer actual, no a los demás jugadores.
 * Se utiliza un PrivateChannel específico del usuario para mantener la palabra secreta.
 */
class WordRevealedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $drawerId;
    public string $word;
    public string $difficulty;
    public int $roundNumber;

    /**
     * Create a new event instance.
     *
     * @param GameMatch $match La partida actual
     * @param Player $drawer El jugador que dibujará (drawer)
     * @param string $word La palabra a dibujar
     * @param string $difficulty La dificultad de la palabra (easy/medium/hard)
     * @param int $roundNumber El número de ronda actual
     */
    public function __construct(
        GameMatch $match,
        Player $drawer,
        string $word,
        string $difficulty,
        int $roundNumber
    ) {
        $this->roomCode = $match->room->code;
        $this->drawerId = $drawer->id;
        $this->word = $word;
        $this->difficulty = $difficulty;
        $this->roundNumber = $roundNumber;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Usa un canal privado del usuario para que solo el drawer reciba la palabra.
     */
    public function broadcastOn(): PrivateChannel
    {
        // Canal privado del drawer: "private-user.{drawerId}"
        return new PrivateChannel("user.{$this->drawerId}");
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'pictionary.word-revealed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->roomCode,
            'drawer_id' => $this->drawerId,
            'word' => $this->word,
            'difficulty' => $this->difficulty,
            'round_number' => $this->roundNumber,
        ];
    }
}

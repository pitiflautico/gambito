<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando el timer de una ronda expira.
 *
 * Este evento es genérico y NO decide qué hacer.
 * Cada juego debe implementar onRoundTimerExpired() en su Engine
 * para decidir cómo manejar la expiración:
 * - Trivia: completar ronda
 * - Pictionary: pasar turno
 * - Otro juego: penalizar pero continuar
 */
class RoundTimerExpiredEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $roundNumber;
    public string $timerName;

    public function __construct(
        GameMatch $match,
        int $roundNumber,
        string $timerName = 'round'
    ) {
        $this->roomCode = $match->room->code;
        $this->roundNumber = $roundNumber;
        $this->timerName = $timerName;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.round.timer.expired';
    }

    public function broadcastWith(): array
    {
        return [
            'round_number' => $this->roundNumber,
            'timer_name' => $this->timerName,
        ];
    }
}

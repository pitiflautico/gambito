<?php

namespace App\Events\Game;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que indica que debe iniciarse una nueva ronda.
 *
 * Este evento es NO broadcast (solo backend).
 * Cuando se emite, un listener:
 * 1. Avanza el número de ronda
 * 2. Llama a Engine->handleNewRound()
 * 3. Emite RoundStartedEvent (que SÍ se broadcast al frontend)
 */
class StartNewRoundEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $matchId;
    public string $roomCode;

    public function __construct(int $matchId, string $roomCode)
    {
        $this->matchId = $matchId;
        $this->roomCode = $roomCode;
    }
}

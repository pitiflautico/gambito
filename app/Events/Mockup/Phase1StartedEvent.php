<?php

namespace App\Events\Mockup;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando inicia la Fase 1 en MockupGame.
 *
 * Este evento se emite ANTES de iniciar el timer de la fase,
 * permitiendo al juego preparar datos, cargar recursos, etc.
 */
class Phase1StartedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $matchId;
    public string $phase;
    public ?int $duration;

    /**
     * Create a new event instance.
     */
    public function __construct(GameMatch $match, array $phaseConfig)
    {
        $this->roomCode = $match->room->code;
        $this->matchId = $match->id;
        $this->phase = 'phase1';
        $this->duration = $phaseConfig['duration'] ?? null;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('room.' . $this->roomCode);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'mockup.phase1.started';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'match_id' => $this->matchId,
            'phase' => $this->phase,
            'duration' => $this->duration,
        ];
    }
}

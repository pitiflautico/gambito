<?php

namespace App\Events\Trivia;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Phase2StartedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $phase;
    public ?int $duration;
    public string $timerId;
    public int $serverTime;
    public array $phaseData;

    public function __construct(GameMatch $match, array $phaseConfig)
    {
        $this->roomCode = $match->room->code;
        $this->phase = 'answering';
        $this->duration = $phaseConfig['duration'] ?? null;
        $this->timerId = 'timer';
        $this->serverTime = now()->timestamp;
        $this->phaseData = $phaseConfig;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('room.' . $this->roomCode);
    }

    public function broadcastAs(): string
    {
        return 'trivia.answering.started';
    }

    public function broadcastWith(): array
    {
        // Intentar incluir opciones desde game_state (si el engine las guardó en _ui)
        $options = $this->phaseData['ui']['options'] ?? null;
        $correctIndex = $this->phaseData['ui']['correct_index'] ?? null;

        return [
            'room_code' => $this->roomCode,
            'phase_name' => $this->phase,
            'duration' => $this->duration,
            'timer_id' => $this->timerId,
            'server_time' => $this->serverTime,
            'phase_data' => $this->phaseData,
            'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',
            // Campos adicionales para frontend
            'options' => $options,
            'correct_index' => $correctIndex,
        ];
    }
}



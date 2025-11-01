<?php

namespace App\Events\Pictionary;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DrawingStartedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public GameMatch $match;
    public string $roomCode;
    public string $phase;
    public int $duration;
    public string $timerId;
    public int $serverTime;
    public array $phaseData;

    public function __construct(GameMatch $match, array $phaseConfig)
    {
        $this->match = $match;
        $this->roomCode = $match->room->code;
        $this->phase = 'drawing'; // Mismo nombre que config.json phases[].name
        // Asegurar que duration no sea null (TimingModule lo requiere)
        $this->duration = $phaseConfig['duration'] ?? 20;
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
        return 'pictionary.drawing.started'; // SIN punto inicial
    }

    public function broadcastWith(): array
    {
        // Asegurar estado fresco por si hubo writes antes de emitir
        $this->match = $this->match->fresh();
        
        // Log payload values before return
        \Illuminate\Support\Facades\Log::info('[Pictionary] DrawingStartedEvent payload', [
            'room_code' => $this->roomCode,
            'phase' => $this->phase,
            'duration' => $this->duration,
            'timer_id' => $this->timerId,
            'server_time' => $this->serverTime,
            'has_ui' => !empty($this->match->game_state['_ui'] ?? []),
        ]);

        // IMPORTANTE: Estructura exacta igual a Trivia Phase1StartedEvent para TimingModule
        return [
            'room_code' => $this->roomCode,
            'phase_name' => $this->phase,
            'duration' => $this->duration, // TimingModule requiere este campo
            'timer_id' => $this->timerId, // 'timer' - debe coincidir con id="timer" en HTML
            'server_time' => $this->serverTime, // TimingModule requiere este campo
            'phase_data' => $this->phaseData,
            'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',
        ];
    }
}


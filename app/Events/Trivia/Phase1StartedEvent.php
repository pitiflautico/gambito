<?php

namespace App\Events\Trivia;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Phase1StartedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public GameMatch $match;
    public string $roomCode;
    public string $phase;
    public ?int $duration;
    public string $timerId;
    public int $serverTime;
    public array $phaseData;

    public function __construct(GameMatch $match, array $phaseConfig)
    {
        $this->match = $match;
        $this->roomCode = $match->room->code;
        $this->phase = 'question';
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
        return 'trivia.question.started';
    }

    public function broadcastWith(): array
    {
        // Asegurar estado fresco por si hubo writes antes de emitir
        $this->match = $this->match->fresh();
        // Datos de UI: leer desde game_state guardado por el Engine en startNewRound()
        $ui = $this->match->game_state['_ui'] ?? [];
        $questionText = $ui['phases']['question']['text'] ?? null;
        $options = $ui['phases']['answering']['options'] ?? null;
        $correctIndex = $ui['phases']['answering']['correct_option'] ?? null;

        // Log payload values before return
        \Illuminate\Support\Facades\Log::info('[Trivia] Phase1StartedEvent payload', [
            'question_text' => $questionText ? substr($questionText, 0, 50) . '...' : 'NULL',
            'options_count' => is_array($options) ? count($options) : 'NULL',
            'correct_index' => $correctIndex ?? 'NULL',
            'has_ui' => !empty($ui),
            'ui_keys' => array_keys($ui),
        ]);

        return [
            'room_code' => $this->roomCode,
            'phase_name' => $this->phase,
            'duration' => $this->duration,
            'timer_id' => $this->timerId,
            'server_time' => $this->serverTime,
            'phase_data' => $this->phaseData,
            'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',
            // Campos adicionales para frontend
            'question_text' => $questionText,
            'options' => $options,
            'correct_index' => $correctIndex,
        ];
    }
}



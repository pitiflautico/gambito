<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando el juego termina completamente.
 */
class GameEndedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public ?int $winner;
    public array $ranking;
    public array $scores;

    public function __construct(
        GameMatch $match,
        ?int $winner,
        array $ranking,
        array $scores
    ) {
        $this->roomCode = $match->room->code;
        $this->winner = $winner;
        $this->ranking = $ranking;
        $this->scores = $scores;

        \Log::info('🏁 [GameEndedEvent] Event created', [
            'room_code' => $this->roomCode,
            'winner' => $winner,
            'ranking_count' => count($ranking),
        ]);
    }

    public function broadcastOn(): PresenceChannel
    {
        $channel = new PresenceChannel("room.{$this->roomCode}");
        \Log::info('🏁 [GameEndedEvent] broadcastOn() called', [
            'channel' => "presence-room.{$this->roomCode}",
        ]);
        return $channel;
    }

    public function broadcastAs(): string
    {
        \Log::info('🏁 [GameEndedEvent] broadcastAs() called', [
            'event_name' => 'game.finished',
        ]);
        return 'game.finished';
    }

    public function broadcastWith(): array
    {
        $data = [
            'winner' => $this->winner,
            'ranking' => $this->ranking,
            'scores' => $this->scores,
        ];
        \Log::info('🏁 [GameEndedEvent] broadcastWith() called', [
            'data_keys' => array_keys($data),
        ]);
        return $data;
    }
}


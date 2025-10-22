<?php

namespace Games\Pictionary\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se dispara cuando el dibujante hace un trazo en el canvas
 * o limpia el canvas.
 *
 * Este evento se broadcast a todos los jugadores en la sala para sincronizar
 * el canvas en tiempo real.
 */
class CanvasDrawEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $action;
    public ?array $stroke;

    /**
     * Create a new event instance.
     *
     * @param string $roomCode Código de la sala
     * @param string $action 'draw' o 'clear'
     * @param array|null $stroke Datos del trazo (x0, y0, x1, y1, color, size) - solo para 'draw'
     */
    public function __construct(string $roomCode, string $action, ?array $stroke = null)
    {
        $this->roomCode = $roomCode;
        $this->action = $action;
        $this->stroke = $stroke;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // TODO: En producción usar PrivateChannel con autenticación
        // Por ahora usamos Channel público para el demo
        return [
            new Channel('room.' . $this->roomCode),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'pictionary.canvas.draw';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        $data = [
            'action' => $this->action,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($this->action === 'draw' && $this->stroke) {
            $data['stroke'] = $this->stroke;
        }

        return $data;
    }
}

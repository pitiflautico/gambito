<?php

namespace App\Events\Game;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento: Countdown antes de iniciar el juego
 *
 * Sistema timestamp-based (gaming industry standard):
 * - Backend envía UN evento con timestamp preciso del servidor
 * - Frontend calcula remaining time localmente con requestAnimationFrame
 * - Compensa automáticamente drift y lag de red
 * - Sincronización perfecta entre todos los clientes
 *
 * Usado en: Fortnite, CS:GO, Rocket League, League of Legends
 */
class GameCountdownEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public float $serverTimestamp;
    public int $durationMs;

    public function __construct(
        public Room $room,
        int $countdownSeconds
    ) {
        // Capturar timestamp del servidor con precisión de microsegundos
        $this->serverTimestamp = microtime(true);
        $this->durationMs = $countdownSeconds * 1000;
    }

    /**
     * Nombre del evento en el cliente
     */
    public function broadcastAs(): string
    {
        return 'game.countdown';
    }

    /**
     * Datos que se envían al cliente
     */
    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->room->code,
            'server_time' => $this->serverTimestamp,     // Timestamp preciso (ej: 1735140000.123456)
            'duration_ms' => $this->durationMs,          // Duración en milisegundos
            'seconds' => intval($this->durationMs / 1000), // Para compatibilidad
            'message' => 'El juego comenzará en...',
            'action' => 'initialize_engine',
        ];
    }

    /**
     * Canal donde se broadcastea
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('room.' . $this->room->code),
        ];
    }
}

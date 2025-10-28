<?php

namespace App\Services\Modules\TimerSystem;

use DateTime;

/**
 * Representa un timer individual.
 *
 * Esta clase encapsula toda la lógica de un timer:
 * - Tiempo de inicio y duración
 * - Estado de pausa
 * - Cálculo de tiempo transcurrido/restante
 * - Detección de expiración
 */
class Timer
{
    /**
     * Nombre único del timer.
     */
    protected string $name;

    /**
     * Duración total en segundos.
     */
    protected int $duration;

    /**
     * Timestamp de inicio.
     */
    protected DateTime $startedAt;

    /**
     * Si el timer está pausado.
     */
    protected bool $isPaused = false;

    /**
     * Timestamp cuando se pausó.
     */
    protected ?DateTime $pausedAt = null;

    /**
     * Tiempo acumulado en pausa (segundos).
     */
    protected int $totalPausedSeconds = 0;

    /**
     * Clase del evento a emitir cuando expire (ej: RoundEndedEvent::class).
     */
    protected ?string $eventToEmit = null;

    /**
     * Datos a pasar al evento cuando expire.
     */
    protected array $eventData = [];

    /**
     * Constructor.
     *
     * @param string $name Nombre del timer
     * @param int $duration Duración en segundos
     * @param DateTime $startedAt Timestamp de inicio
     * @param string|null $eventToEmit Clase del evento a emitir cuando expire
     * @param array $eventData Datos a pasar al evento
     * @param bool $isPaused Si está pausado
     * @param DateTime|null $pausedAt Cuándo se pausó
     * @param int $totalPausedSeconds Tiempo total pausado
     */
    public function __construct(
        string $name,
        int $duration,
        DateTime $startedAt,
        ?string $eventToEmit = null,
        array $eventData = [],
        bool $isPaused = false,
        ?DateTime $pausedAt = null,
        int $totalPausedSeconds = 0
    ) {
        $this->name = $name;
        $this->duration = $duration;
        $this->startedAt = $startedAt;
        $this->eventToEmit = $eventToEmit;
        $this->eventData = $eventData;
        $this->isPaused = $isPaused;
        $this->pausedAt = $pausedAt;
        $this->totalPausedSeconds = $totalPausedSeconds;
    }

    /**
     * Pausar el timer.
     *
     * @return void
     */
    public function pause(): void
    {
        if ($this->isPaused) {
            return; // Ya está pausado
        }

        $this->isPaused = true;
        $this->pausedAt = new DateTime();
    }

    /**
     * Reanudar el timer.
     *
     * @return void
     */
    public function resume(): void
    {
        if (!$this->isPaused) {
            return; // No está pausado
        }

        if ($this->pausedAt) {
            $now = new DateTime();
            $pausedSeconds = $now->getTimestamp() - $this->pausedAt->getTimestamp();
            $this->totalPausedSeconds += $pausedSeconds;
        }

        $this->isPaused = false;
        $this->pausedAt = null;
    }

    /**
     * Obtener tiempo transcurrido (excluyendo tiempo pausado).
     *
     * @return int Segundos transcurridos
     */
    public function getElapsedTime(): int
    {
        $now = new DateTime();
        $totalSeconds = $now->getTimestamp() - $this->startedAt->getTimestamp();

        // Restar tiempo pausado
        $pausedSeconds = $this->totalPausedSeconds;

        // Si está pausado actualmente, NO contar el tiempo desde la pausa
        if ($this->isPaused && $this->pausedAt) {
            // No añadimos tiempo adicional, el tiempo está congelado
            $totalSeconds = $this->pausedAt->getTimestamp() - $this->startedAt->getTimestamp();
        }

        return max(0, $totalSeconds - $pausedSeconds);
    }

    /**
     * Obtener tiempo restante.
     *
     * @return int Segundos restantes (0 si expiró)
     */
    public function getRemainingTime(): int
    {
        $elapsed = $this->getElapsedTime();
        return max(0, $this->duration - $elapsed);
    }

    /**
     * Verificar si el timer ha expirado.
     *
     * @return bool True si el tiempo se acabó
     */
    public function isExpired(): bool
    {
        return $this->getRemainingTime() === 0;
    }

    /**
     * Verificar si está pausado.
     *
     * @return bool
     */
    public function isPaused(): bool
    {
        return $this->isPaused;
    }

    /**
     * Obtener nombre del timer.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Obtener duración total.
     *
     * @return int Segundos
     */
    public function getDuration(): int
    {
        return $this->duration;
    }

    /**
     * Obtener timestamp de inicio.
     *
     * @return DateTime
     */
    public function getStartedAt(): DateTime
    {
        return $this->startedAt;
    }

    /**
     * Obtener clase del evento a emitir.
     *
     * @return string|null
     */
    public function getEventToEmit(): ?string
    {
        return $this->eventToEmit;
    }

    /**
     * Obtener datos del evento.
     *
     * @return array
     */
    public function getEventData(): array
    {
        return $this->eventData;
    }

    /**
     * Serializar a array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'duration' => $this->duration,
            'started_at' => $this->startedAt->format('Y-m-d H:i:s'),
            'event_to_emit' => $this->eventToEmit,
            'event_data' => $this->eventData,
            'is_paused' => $this->isPaused,
            'paused_at' => $this->pausedAt ? $this->pausedAt->format('Y-m-d H:i:s') : null,
            'total_paused_seconds' => $this->totalPausedSeconds,
        ];
    }

    /**
     * Restaurar desde array.
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            duration: $data['duration'],
            startedAt: new DateTime($data['started_at']),
            eventToEmit: $data['event_to_emit'] ?? null,
            eventData: $data['event_data'] ?? [],
            isPaused: $data['is_paused'] ?? false,
            pausedAt: isset($data['paused_at']) ? new DateTime($data['paused_at']) : null,
            totalPausedSeconds: $data['total_paused_seconds'] ?? 0
        );
    }
}

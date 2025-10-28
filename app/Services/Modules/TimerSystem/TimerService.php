<?php

namespace App\Services\Modules\TimerSystem;

use DateTime;

/**
 * Servicio genérico para gestionar timers/temporizadores en juegos.
 *
 * Este módulo permite gestionar múltiples timers simultáneos con diferentes
 * duraciones, pausas, y estados. Es útil para juegos que necesitan:
 * - Timeouts por turno
 * - Límites de tiempo por pregunta
 * - Cooldowns de habilidades
 * - Tiempo total de partida
 *
 * Características:
 * - Múltiples timers simultáneos
 * - Pause/resume individual
 * - Cálculo de tiempo restante
 * - Detección de expiración
 * - Serialización completa
 *
 * Nota: Este servicio NO ejecuta callbacks automáticamente (no usa cron/jobs).
 * El juego debe consultar getRemainingTime() o isExpired() periódicamente.
 */
class TimerService
{
    /**
     * Timers activos.
     *
     * @var array<string, Timer>
     */
    protected array $timers = [];

    /**
     * Constructor.
     *
     * @param array $timers Timers existentes (para fromArray)
     */
    public function __construct(array $timers = [])
    {
        $this->timers = $timers;
    }

    /**
     * Iniciar un nuevo timer.
     *
     * @param string $timerName Nombre único del timer
     * @param int $durationSeconds Duración en segundos
     * @param string|null $eventToEmit Clase del evento a emitir cuando expire (ej: RoundEndedEvent::class)
     * @param array $eventData Datos a pasar al evento cuando expire
     * @param DateTime|null $startTime Tiempo de inicio (default: now)
     * @param bool $restart Si true, reinicia el timer si ya existe (default: false)
     * @return Timer El timer creado
     * @throws \InvalidArgumentException Si el timer ya existe y $restart es false
     */
    public function startTimer(
        string $timerName,
        int $durationSeconds,
        ?string $eventToEmit = null,
        array $eventData = [],
        ?DateTime $startTime = null,
        bool $restart = false
    ): Timer {
        if (isset($this->timers[$timerName]) && !$restart) {
            throw new \InvalidArgumentException("Timer '{$timerName}' ya existe");
        }

        if ($durationSeconds <= 0) {
            throw new \InvalidArgumentException("La duración debe ser mayor a 0");
        }

        $timer = new Timer(
            name: $timerName,
            duration: $durationSeconds,
            startedAt: $startTime ?? new DateTime(),
            eventToEmit: $eventToEmit,
            eventData: $eventData
        );

        $this->timers[$timerName] = $timer;

        return $timer;
    }

    /**
     * Pausar un timer.
     *
     * @param string $timerName Nombre del timer
     * @return void
     * @throws \InvalidArgumentException Si el timer no existe
     */
    public function pauseTimer(string $timerName): void
    {
        $timer = $this->getTimer($timerName);
        $timer->pause();
    }

    /**
     * Pausar TODOS los timers activos.
     * Usado cuando un jugador se desconecta.
     *
     * @return void
     */
    public function pauseAllTimers(): void
    {
        foreach ($this->timers as $timer) {
            if (!$timer->isPaused()) {
                $timer->pause();
            }
        }
    }

    /**
     * Reanudar un timer pausado.
     *
     * @param string $timerName Nombre del timer
     * @return void
     */
    public function resumeTimer(string $timerName): void
    {
        $timer = $this->getTimer($timerName);
        $timer->resume();
    }

    /**
     * Cancelar y eliminar un timer.
     *
     * Útil cuando un timer ya no es necesario (ej: una ronda terminó antes del timeout).
     * El timer se elimina y NO emitirá ningún evento.
     *
     * @param string $timerName Nombre del timer
     * @return bool True si se canceló, false si no existía
     */
    public function cancelTimer(string $timerName): bool
    {
        if (!$this->hasTimer($timerName)) {
            return false;
        }

        unset($this->timers[$timerName]);
        return true;
    }

    /**
     * Obtener tiempo restante de un timer (en segundos).
     *
     * @param string $timerName Nombre del timer
     * @return int Segundos restantes (0 si expiró)
     */
    public function getRemainingTime(string $timerName): int
    {
        $timer = $this->getTimer($timerName);
        return $timer->getRemainingTime();
    }

    /**
     * Obtener tiempo transcurrido de un timer (en segundos).
     *
     * @param string $timerName Nombre del timer
     * @return int Segundos transcurridos
     */
    public function getElapsedTime(string $timerName): int
    {
        $timer = $this->getTimer($timerName);
        return $timer->getElapsedTime();
    }

    /**
     * Verificar si un timer ha expirado.
     *
     * @param string $timerName Nombre del timer
     * @return bool True si expiró
     */
    public function isExpired(string $timerName): bool
    {
        $timer = $this->getTimer($timerName);
        return $timer->isExpired();
    }

    /**
     * Verificar si un timer está pausado.
     *
     * @param string $timerName Nombre del timer
     * @return bool True si está pausado
     */
    public function isPaused(string $timerName): bool
    {
        $timer = $this->getTimer($timerName);
        return $timer->isPaused();
    }

    /**
     * Reiniciar un timer (vuelve a empezar desde 0).
     *
     * @param string $timerName Nombre del timer
     * @param int|null $newDuration Nueva duración (opcional, usa la anterior si no se provee)
     * @return void
     */
    public function restartTimer(string $timerName, ?int $newDuration = null): void
    {
        $timer = $this->getTimer($timerName);
        $duration = $newDuration ?? $timer->getDuration();

        // Recrear el timer
        $this->cancelTimer($timerName);
        $this->startTimer($timerName, $duration);
    }

    /**
     * Verificar si existe un timer.
     *
     * @param string $timerName Nombre del timer
     * @return bool True si existe
     */
    public function hasTimer(string $timerName): bool
    {
        return isset($this->timers[$timerName]);
    }

    /**
     * Obtener todos los timers.
     *
     * @return array<string, Timer>
     */
    public function getTimers(): array
    {
        return $this->timers;
    }

    /**
     * Obtener un timer específico.
     *
     * @param string $timerName Nombre del timer
     * @return Timer
     * @throws \InvalidArgumentException Si no existe
     */
    public function getTimer(string $timerName): Timer
    {
        if (!isset($this->timers[$timerName])) {
            throw new \InvalidArgumentException("Timer '{$timerName}' no existe");
        }

        return $this->timers[$timerName];
    }

    /**
     * Cancelar todos los timers.
     *
     * @return void
     */
    public function cancelAllTimers(): void
    {
        $this->timers = [];
    }

    /**
     * Obtener información de todos los timers.
     *
     * @return array Array con información de cada timer
     */
    public function getAllTimersInfo(): array
    {
        $info = [];

        foreach ($this->timers as $name => $timer) {
            $info[$name] = [
                'name' => $name,
                'duration' => $timer->getDuration(),
                'elapsed' => $timer->getElapsedTime(),
                'remaining' => $timer->getRemainingTime(),
                'is_expired' => $timer->isExpired(),
                'is_paused' => $timer->isPaused(),
            ];
        }

        return $info;
    }

    /**
     * Serializar a array para persistencia.
     *
     * @return array Estado serializado
     */
    public function toArray(): array
    {
        $timersData = [];

        foreach ($this->timers as $name => $timer) {
            $timersData[$name] = $timer->toArray();
        }

        return [
            'timer_system' => [
                'timers' => $timersData,
            ]
        ];
    }

    /**
     * Restaurar desde array serializado.
     *
     * @param array $data Estado serializado
     * @return self Nueva instancia restaurada
     */
    public static function fromArray(array $data): self
    {
        $timers = [];

        // Soporte para ambos formatos: nuevo (timer_system) y legacy (claves directas)
        $timerData = $data['timer_system'] ?? $data;

        if (isset($timerData['timers'])) {
            foreach ($timerData['timers'] as $name => $timerInfo) {
                $timers[$name] = Timer::fromArray($timerInfo);
            }
        }

        return new self($timers);
    }

    /**
     * Emitir evento cuando un timer expira.
     *
     * Este método lee la configuración del timer (eventToEmit + eventData)
     * y emite el evento especificado cuando el timer fue creado.
     *
     * Si el timer no tiene evento configurado, no emite nada.
     *
     * @param string $timerName Nombre del timer que expiró
     * @return bool True si emitió evento, false si no había evento configurado
     */
    public function emitTimerExpiredEvent(string $timerName): bool
    {
        if (!$this->hasTimer($timerName)) {
            \Log::warning('[TimerService] Intentando emitir evento de timer inexistente', [
                'timer_name' => $timerName
            ]);
            return false;
        }

        $timer = $this->getTimer($timerName);
        $eventToEmit = $timer->getEventToEmit();
        $eventData = $timer->getEventData();

        // Si no hay evento configurado, no hacer nada
        if (!$eventToEmit) {
            \Log::info('[TimerService] Timer sin evento configurado', [
                'timer_name' => $timerName
            ]);
            return false;
        }

        \Log::info("⏰ [BACKEND] Emitiendo evento de timer - Timer: {$timerName}, Evento: {$eventToEmit}");

        // Instanciar y emitir el evento dinámicamente
        $eventInstance = new $eventToEmit(...array_values($eventData));
        event($eventInstance);

        \Log::info('[TimerService] Evento emitido correctamente', [
            'timer_name' => $timerName,
            'event_class' => $eventToEmit
        ]);

        // Eliminar el timer después de emitir el evento
        $this->cancelTimer($timerName);

        return true;
    }
}

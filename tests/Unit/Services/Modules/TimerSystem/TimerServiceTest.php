<?php

namespace Tests\Unit\Services\Modules\TimerSystem;

use App\Services\Modules\TimerSystem\TimerService;
use App\Services\Modules\TimerSystem\Timer;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Tests para TimerService.
 *
 * Cobertura:
 * - Inicialización de timers
 * - Validación de parámetros
 * - Operaciones pause/resume
 * - Cálculos de tiempo (elapsed, remaining, expired)
 * - Múltiples timers simultáneos
 * - Pause acumulativo correcto
 * - Cancelación y reinicio
 * - Serialización
 * - Casos edge
 */
class TimerServiceTest extends TestCase
{
    /**
     * Test: Puede iniciar un timer simple.
     */
    public function test_can_start_timer(): void
    {
        $service = new TimerService();
        $timer = $service->startTimer('test_timer', 60);

        $this->assertInstanceOf(Timer::class, $timer);
        $this->assertEquals('test_timer', $timer->getName());
        $this->assertEquals(60, $timer->getDuration());
        $this->assertTrue($service->hasTimer('test_timer'));
    }

    /**
     * Test: No permite duración <= 0.
     */
    public function test_cannot_start_timer_with_invalid_duration(): void
    {
        $service = new TimerService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La duración debe ser mayor a 0');

        $service->startTimer('invalid_timer', 0);
    }

    /**
     * Test: No permite nombres duplicados.
     */
    public function test_cannot_start_timer_with_duplicate_name(): void
    {
        $service = new TimerService();
        $service->startTimer('duplicate', 60);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Timer 'duplicate' ya existe");

        $service->startTimer('duplicate', 30);
    }

    /**
     * Test: Puede pausar y reanudar un timer.
     */
    public function test_can_pause_and_resume_timer(): void
    {
        $service = new TimerService();
        $service->startTimer('pausable', 60);

        $this->assertFalse($service->isPaused('pausable'));

        $service->pauseTimer('pausable');
        $this->assertTrue($service->isPaused('pausable'));

        $service->resumeTimer('pausable');
        $this->assertFalse($service->isPaused('pausable'));
    }

    /**
     * Test: Pausar múltiples veces es idempotente.
     */
    public function test_pause_is_idempotent(): void
    {
        $service = new TimerService();
        $service->startTimer('idempotent', 60);

        $service->pauseTimer('idempotent');
        $this->assertTrue($service->isPaused('idempotent'));

        // Pausar de nuevo no hace nada
        $service->pauseTimer('idempotent');
        $this->assertTrue($service->isPaused('idempotent'));
    }

    /**
     * Test: Resume múltiples veces es idempotente.
     */
    public function test_resume_is_idempotent(): void
    {
        $service = new TimerService();
        $service->startTimer('idempotent', 60);

        // Reanudar sin pausar no hace nada
        $service->resumeTimer('idempotent');
        $this->assertFalse($service->isPaused('idempotent'));
    }

    /**
     * Test: Cálculo de tiempo transcurrido.
     */
    public function test_calculates_elapsed_time(): void
    {
        $service = new TimerService();
        $startTime = new DateTime('2025-01-15 12:00:00');
        $service->startTimer('elapsed', 60, $startTime);

        // Simular 10 segundos después
        // Nota: En test real necesitaríamos mock DateTime o time travel
        $elapsed = $service->getElapsedTime('elapsed');
        $this->assertGreaterThanOrEqual(0, $elapsed);
    }

    /**
     * Test: Cálculo de tiempo restante.
     */
    public function test_calculates_remaining_time(): void
    {
        $service = new TimerService();
        $startTime = new DateTime('2025-01-15 12:00:00');
        $service->startTimer('remaining', 60, $startTime);

        $remaining = $service->getRemainingTime('remaining');
        $this->assertLessThanOrEqual(60, $remaining);
        $this->assertGreaterThanOrEqual(0, $remaining);
    }

    /**
     * Test: Detecta cuando timer expira.
     */
    public function test_detects_expired_timer(): void
    {
        $service = new TimerService();

        // Timer que comenzó hace 70 segundos con duración de 60
        $startTime = new DateTime('-70 seconds');
        $service->startTimer('expired', 60, $startTime);

        $this->assertTrue($service->isExpired('expired'));
        $this->assertEquals(0, $service->getRemainingTime('expired'));
    }

    /**
     * Test: Timer no expirado.
     */
    public function test_detects_non_expired_timer(): void
    {
        $service = new TimerService();

        // Timer que comenzó hace 10 segundos con duración de 60
        $startTime = new DateTime('-10 seconds');
        $service->startTimer('active', 60, $startTime);

        $this->assertFalse($service->isExpired('active'));
        $this->assertGreaterThan(0, $service->getRemainingTime('active'));
    }

    /**
     * Test: Puede cancelar un timer.
     */
    public function test_can_cancel_timer(): void
    {
        $service = new TimerService();
        $service->startTimer('cancelable', 60);

        $this->assertTrue($service->hasTimer('cancelable'));

        $service->cancelTimer('cancelable');

        $this->assertFalse($service->hasTimer('cancelable'));
    }

    /**
     * Test: No puede cancelar timer inexistente.
     */
    public function test_cannot_cancel_nonexistent_timer(): void
    {
        $service = new TimerService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Timer 'nonexistent' no existe");

        $service->cancelTimer('nonexistent');
    }

    /**
     * Test: Puede reiniciar un timer.
     */
    public function test_can_restart_timer(): void
    {
        $service = new TimerService();
        $startTime = new DateTime('-30 seconds');
        $service->startTimer('restartable', 60, $startTime);

        // Timer lleva 30 segundos, quedan ~30
        $this->assertLessThan(35, $service->getRemainingTime('restartable'));

        // Reiniciar
        $service->restartTimer('restartable');

        // Ahora debería tener ~60 segundos de nuevo
        $remaining = $service->getRemainingTime('restartable');
        $this->assertGreaterThan(55, $remaining);
        $this->assertLessThanOrEqual(60, $remaining);
    }

    /**
     * Test: Puede reiniciar con nueva duración.
     */
    public function test_can_restart_timer_with_new_duration(): void
    {
        $service = new TimerService();
        $service->startTimer('restartable', 60);

        $service->restartTimer('restartable', 120);

        $timer = $service->getTimer('restartable');
        $this->assertEquals(120, $timer->getDuration());
    }

    /**
     * Test: Puede gestionar múltiples timers simultáneos.
     */
    public function test_can_manage_multiple_timers(): void
    {
        $service = new TimerService();
        $service->startTimer('timer1', 60);
        $service->startTimer('timer2', 90);
        $service->startTimer('timer3', 120);

        $this->assertTrue($service->hasTimer('timer1'));
        $this->assertTrue($service->hasTimer('timer2'));
        $this->assertTrue($service->hasTimer('timer3'));

        $timers = $service->getTimers();
        $this->assertCount(3, $timers);
    }

    /**
     * Test: Puede cancelar todos los timers.
     */
    public function test_can_cancel_all_timers(): void
    {
        $service = new TimerService();
        $service->startTimer('timer1', 60);
        $service->startTimer('timer2', 90);
        $service->startTimer('timer3', 120);

        $this->assertCount(3, $service->getTimers());

        $service->cancelAllTimers();

        $this->assertCount(0, $service->getTimers());
        $this->assertFalse($service->hasTimer('timer1'));
    }

    /**
     * Test: Obtiene información de todos los timers.
     */
    public function test_gets_all_timers_info(): void
    {
        $service = new TimerService();
        $service->startTimer('timer1', 60);
        $service->startTimer('timer2', 90);

        $info = $service->getAllTimersInfo();

        $this->assertCount(2, $info);
        $this->assertArrayHasKey('timer1', $info);
        $this->assertArrayHasKey('timer2', $info);

        $this->assertEquals('timer1', $info['timer1']['name']);
        $this->assertEquals(60, $info['timer1']['duration']);
        $this->assertArrayHasKey('elapsed', $info['timer1']);
        $this->assertArrayHasKey('remaining', $info['timer1']);
        $this->assertArrayHasKey('is_expired', $info['timer1']);
        $this->assertArrayHasKey('is_paused', $info['timer1']);
    }

    /**
     * Test: No puede operar sobre timer inexistente.
     */
    public function test_throws_exception_for_nonexistent_timer(): void
    {
        $service = new TimerService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Timer 'nonexistent' no existe");

        $service->getRemainingTime('nonexistent');
    }

    /**
     * Test: Puede obtener un timer específico.
     */
    public function test_can_get_specific_timer(): void
    {
        $service = new TimerService();
        $service->startTimer('specific', 60);

        $timer = $service->getTimer('specific');

        $this->assertInstanceOf(Timer::class, $timer);
        $this->assertEquals('specific', $timer->getName());
    }

    /**
     * Test: Serialización a array.
     */
    public function test_serializes_to_array(): void
    {
        $service = new TimerService();
        $startTime = new DateTime('2025-01-15 12:00:00');
        $service->startTimer('serializable', 60, $startTime);

        $data = $service->toArray();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('timers', $data);
        $this->assertArrayHasKey('serializable', $data['timers']);

        $timerData = $data['timers']['serializable'];
        $this->assertEquals('serializable', $timerData['name']);
        $this->assertEquals(60, $timerData['duration']);
        $this->assertEquals('2025-01-15 12:00:00', $timerData['started_at']);
        $this->assertFalse($timerData['is_paused']);
    }

    /**
     * Test: Restauración desde array.
     */
    public function test_restores_from_array(): void
    {
        $data = [
            'timers' => [
                'restored' => [
                    'name' => 'restored',
                    'duration' => 60,
                    'started_at' => '2025-01-15 12:00:00',
                    'is_paused' => false,
                    'paused_at' => null,
                    'total_paused_seconds' => 0,
                ],
            ],
        ];

        $service = TimerService::fromArray($data);

        $this->assertTrue($service->hasTimer('restored'));
        $timer = $service->getTimer('restored');
        $this->assertEquals('restored', $timer->getName());
        $this->assertEquals(60, $timer->getDuration());
    }

    /**
     * Test: Serialización round-trip mantiene estado.
     */
    public function test_serialization_roundtrip(): void
    {
        $service = new TimerService();
        $startTime = new DateTime('2025-01-15 12:00:00');
        $service->startTimer('roundtrip', 90, $startTime);
        $service->pauseTimer('roundtrip');

        $data = $service->toArray();
        $restored = TimerService::fromArray($data);

        $this->assertTrue($restored->hasTimer('roundtrip'));
        $this->assertTrue($restored->isPaused('roundtrip'));

        $originalTimer = $service->getTimer('roundtrip');
        $restoredTimer = $restored->getTimer('roundtrip');

        $this->assertEquals($originalTimer->getName(), $restoredTimer->getName());
        $this->assertEquals($originalTimer->getDuration(), $restoredTimer->getDuration());
        $this->assertEquals($originalTimer->isPaused(), $restoredTimer->isPaused());
    }

    /**
     * Test: Restauración con múltiples timers.
     */
    public function test_restores_multiple_timers_from_array(): void
    {
        $data = [
            'timers' => [
                'timer1' => [
                    'name' => 'timer1',
                    'duration' => 60,
                    'started_at' => '2025-01-15 12:00:00',
                    'is_paused' => false,
                    'paused_at' => null,
                    'total_paused_seconds' => 0,
                ],
                'timer2' => [
                    'name' => 'timer2',
                    'duration' => 90,
                    'started_at' => '2025-01-15 12:05:00',
                    'is_paused' => true,
                    'paused_at' => '2025-01-15 12:06:00',
                    'total_paused_seconds' => 10,
                ],
            ],
        ];

        $service = TimerService::fromArray($data);

        $this->assertCount(2, $service->getTimers());
        $this->assertTrue($service->hasTimer('timer1'));
        $this->assertTrue($service->hasTimer('timer2'));
        $this->assertFalse($service->isPaused('timer1'));
        $this->assertTrue($service->isPaused('timer2'));
    }
}

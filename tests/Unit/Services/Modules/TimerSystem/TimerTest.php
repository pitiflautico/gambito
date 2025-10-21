<?php

namespace Tests\Unit\Services\Modules\TimerSystem;

use App\Services\Modules\TimerSystem\Timer;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Tests para Timer.
 *
 * Cobertura:
 * - Inicialización
 * - Pause/resume mecánica
 * - Cálculo de tiempo transcurrido
 * - Cálculo de tiempo restante
 * - Detección de expiración
 * - Pause acumulativo correcto
 * - Serialización
 * - Casos edge (pause múltiple, timer expirado pausado, etc.)
 */
class TimerTest extends TestCase
{
    /**
     * Test: Constructor inicializa correctamente.
     */
    public function test_constructor_initializes_correctly(): void
    {
        $startTime = new DateTime('2025-01-15 12:00:00');
        $timer = new Timer('test_timer', 60, $startTime);

        $this->assertEquals('test_timer', $timer->getName());
        $this->assertEquals(60, $timer->getDuration());
        $this->assertEquals($startTime, $timer->getStartedAt());
        $this->assertFalse($timer->isPaused());
    }

    /**
     * Test: Puede pausar el timer.
     */
    public function test_can_pause_timer(): void
    {
        $timer = new Timer('pausable', 60, new DateTime());

        $this->assertFalse($timer->isPaused());

        $timer->pause();

        $this->assertTrue($timer->isPaused());
    }

    /**
     * Test: Pausar múltiples veces es idempotente.
     */
    public function test_pause_is_idempotent(): void
    {
        $timer = new Timer('idempotent', 60, new DateTime());

        $timer->pause();
        $this->assertTrue($timer->isPaused());

        // Pausar de nuevo no cambia nada
        $timer->pause();
        $this->assertTrue($timer->isPaused());
    }

    /**
     * Test: Puede reanudar el timer.
     */
    public function test_can_resume_timer(): void
    {
        $timer = new Timer('resumable', 60, new DateTime());

        $timer->pause();
        $this->assertTrue($timer->isPaused());

        $timer->resume();
        $this->assertFalse($timer->isPaused());
    }

    /**
     * Test: Resume sin pausar es idempotente.
     */
    public function test_resume_without_pause_is_idempotent(): void
    {
        $timer = new Timer('not_paused', 60, new DateTime());

        $this->assertFalse($timer->isPaused());

        $timer->resume();
        $this->assertFalse($timer->isPaused());
    }

    /**
     * Test: Calcula tiempo transcurrido correctamente.
     */
    public function test_calculates_elapsed_time(): void
    {
        $startTime = new DateTime('-30 seconds');
        $timer = new Timer('elapsed', 60, $startTime);

        $elapsed = $timer->getElapsedTime();

        // Debería ser aproximadamente 30 segundos (con margen de 2s)
        $this->assertGreaterThanOrEqual(28, $elapsed);
        $this->assertLessThanOrEqual(32, $elapsed);
    }

    /**
     * Test: Calcula tiempo restante correctamente.
     */
    public function test_calculates_remaining_time(): void
    {
        $startTime = new DateTime('-20 seconds');
        $timer = new Timer('remaining', 60, $startTime);

        $remaining = $timer->getRemainingTime();

        // Debería ser aproximadamente 40 segundos (con margen de 2s)
        $this->assertGreaterThanOrEqual(38, $remaining);
        $this->assertLessThanOrEqual(42, $remaining);
    }

    /**
     * Test: Detecta timer expirado.
     */
    public function test_detects_expired_timer(): void
    {
        $startTime = new DateTime('-70 seconds');
        $timer = new Timer('expired', 60, $startTime);

        $this->assertTrue($timer->isExpired());
        $this->assertEquals(0, $timer->getRemainingTime());
    }

    /**
     * Test: Detecta timer NO expirado.
     */
    public function test_detects_non_expired_timer(): void
    {
        $startTime = new DateTime('-10 seconds');
        $timer = new Timer('active', 60, $startTime);

        $this->assertFalse($timer->isExpired());
        $this->assertGreaterThan(0, $timer->getRemainingTime());
    }

    /**
     * Test: Timer pausado congela el tiempo transcurrido.
     */
    public function test_paused_timer_freezes_elapsed_time(): void
    {
        $startTime = new DateTime('-10 seconds');
        $timer = new Timer('freeze', 60, $startTime);

        // Esperar un momento y pausar
        sleep(1);
        $timer->pause();

        $elapsedWhenPaused = $timer->getElapsedTime();

        // Esperar otro segundo
        sleep(1);

        // El tiempo transcurrido debería seguir siendo el mismo
        $elapsedAfterPause = $timer->getElapsedTime();

        $this->assertEquals($elapsedWhenPaused, $elapsedAfterPause);
    }

    /**
     * Test: Resume acumula correctamente tiempo pausado.
     */
    public function test_resume_accumulates_paused_time(): void
    {
        $startTime = new DateTime('-10 seconds');
        $timer = new Timer('accumulate', 60, $startTime);

        // Pausar por 2 segundos
        $timer->pause();
        sleep(2);
        $timer->resume();

        // Esperar 1 segundo más
        sleep(1);

        $elapsed = $timer->getElapsedTime();

        // Debería ser ~11 segundos (10 iniciales + 1 después de resume)
        // NO debería incluir los 2 segundos de pausa
        $this->assertGreaterThanOrEqual(10, $elapsed);
        $this->assertLessThanOrEqual(13, $elapsed);
    }

    /**
     * Test: Múltiples pause/resume acumulan correctamente.
     */
    public function test_multiple_pause_resume_accumulates_correctly(): void
    {
        $startTime = new DateTime('-10 seconds');
        $timer = new Timer('multiple', 60, $startTime);

        // Primera pausa: 1 segundo
        $timer->pause();
        sleep(1);
        $timer->resume();

        // Segunda pausa: 1 segundo
        $timer->pause();
        sleep(1);
        $timer->resume();

        // Esperar 1 segundo activo
        sleep(1);

        $elapsed = $timer->getElapsedTime();

        // Debería ser ~11 segundos (10 iniciales + 1 activo final)
        // NO incluir los 2 segundos de pausa
        $this->assertGreaterThanOrEqual(10, $elapsed);
        $this->assertLessThanOrEqual(13, $elapsed);
    }

    /**
     * Test: Timer expirado mientras está pausado.
     */
    public function test_expired_timer_while_paused(): void
    {
        $startTime = new DateTime('-70 seconds');
        $timer = new Timer('expired_paused', 60, $startTime);

        $timer->pause();

        // Aunque esté pausado, si ya expiró antes, sigue expirado
        $this->assertTrue($timer->isExpired());
    }

    /**
     * Test: Serializa a array correctamente.
     */
    public function test_serializes_to_array(): void
    {
        $startTime = new DateTime('2025-01-15 12:00:00');
        $timer = new Timer('serializable', 60, $startTime);

        $data = $timer->toArray();

        $this->assertIsArray($data);
        $this->assertEquals('serializable', $data['name']);
        $this->assertEquals(60, $data['duration']);
        $this->assertEquals('2025-01-15 12:00:00', $data['started_at']);
        $this->assertFalse($data['is_paused']);
        $this->assertNull($data['paused_at']);
        $this->assertEquals(0, $data['total_paused_seconds']);
    }

    /**
     * Test: Serializa timer pausado correctamente.
     */
    public function test_serializes_paused_timer(): void
    {
        $startTime = new DateTime('2025-01-15 12:00:00');
        $timer = new Timer('paused_serializable', 60, $startTime);

        $timer->pause();

        $data = $timer->toArray();

        $this->assertTrue($data['is_paused']);
        $this->assertNotNull($data['paused_at']);
    }

    /**
     * Test: Restaura desde array correctamente.
     */
    public function test_restores_from_array(): void
    {
        $data = [
            'name' => 'restored',
            'duration' => 60,
            'started_at' => '2025-01-15 12:00:00',
            'is_paused' => false,
            'paused_at' => null,
            'total_paused_seconds' => 0,
        ];

        $timer = Timer::fromArray($data);

        $this->assertEquals('restored', $timer->getName());
        $this->assertEquals(60, $timer->getDuration());
        $this->assertFalse($timer->isPaused());
    }

    /**
     * Test: Restaura timer pausado desde array.
     */
    public function test_restores_paused_timer_from_array(): void
    {
        $data = [
            'name' => 'paused_restored',
            'duration' => 60,
            'started_at' => '2025-01-15 12:00:00',
            'is_paused' => true,
            'paused_at' => '2025-01-15 12:01:00',
            'total_paused_seconds' => 10,
        ];

        $timer = Timer::fromArray($data);

        $this->assertEquals('paused_restored', $timer->getName());
        $this->assertTrue($timer->isPaused());
    }

    /**
     * Test: Serialización round-trip mantiene estado.
     */
    public function test_serialization_roundtrip(): void
    {
        $startTime = new DateTime('2025-01-15 12:00:00');
        $original = new Timer('roundtrip', 90, $startTime, true, new DateTime('2025-01-15 12:01:00'), 15);

        $data = $original->toArray();
        $restored = Timer::fromArray($data);

        $this->assertEquals($original->getName(), $restored->getName());
        $this->assertEquals($original->getDuration(), $restored->getDuration());
        $this->assertEquals($original->isPaused(), $restored->isPaused());
        $this->assertEquals(
            $original->getStartedAt()->format('Y-m-d H:i:s'),
            $restored->getStartedAt()->format('Y-m-d H:i:s')
        );
    }

    /**
     * Test: Tiempo transcurrido nunca es negativo.
     */
    public function test_elapsed_time_never_negative(): void
    {
        // Timer que "comenzará" en 10 segundos (futuro)
        $startTime = new DateTime('+10 seconds');
        $timer = new Timer('future', 60, $startTime);

        $elapsed = $timer->getElapsedTime();

        $this->assertGreaterThanOrEqual(0, $elapsed);
    }

    /**
     * Test: Tiempo restante nunca es negativo.
     */
    public function test_remaining_time_never_negative(): void
    {
        $startTime = new DateTime('-70 seconds');
        $timer = new Timer('expired', 60, $startTime);

        $remaining = $timer->getRemainingTime();

        $this->assertEquals(0, $remaining);
    }

    /**
     * Test: Constructor con parámetros de pause restaura estado.
     */
    public function test_constructor_with_pause_parameters(): void
    {
        $startTime = new DateTime('2025-01-15 12:00:00');
        $pausedAt = new DateTime('2025-01-15 12:01:00');

        $timer = new Timer(
            name: 'with_pause',
            duration: 60,
            startedAt: $startTime,
            isPaused: true,
            pausedAt: $pausedAt,
            totalPausedSeconds: 10
        );

        $this->assertTrue($timer->isPaused());
        $this->assertEquals('with_pause', $timer->getName());
    }
}

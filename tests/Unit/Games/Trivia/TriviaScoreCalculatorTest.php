<?php

namespace Tests\Unit\Games\Trivia;

use Games\Trivia\TriviaScoreCalculator;
use Tests\TestCase;

class TriviaScoreCalculatorTest extends TestCase
{
    private TriviaScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new TriviaScoreCalculator();
    }

    /** @test */
    public function calculates_points_for_fast_answer()
    {
        // Responder en primeros 25% del tiempo (0-3.75s de 15s): 100 + 50 = 150 puntos
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 2.5,
            'time_limit' => 15
        ]);

        $this->assertEquals(150, $points);
    }

    /** @test */
    public function calculates_points_for_medium_speed_answer()
    {
        // Responder en primeros 50% del tiempo (3.75-7.5s de 15s): 100 + 30 = 130 puntos
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 6.0,
            'time_limit' => 15
        ]);

        $this->assertEquals(130, $points);
    }

    /** @test */
    public function calculates_points_for_slow_answer()
    {
        // Responder en primeros 75% del tiempo (7.5-11.25s de 15s): 100 + 10 = 110 puntos
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 10.0,
            'time_limit' => 15
        ]);

        $this->assertEquals(110, $points);
    }

    /** @test */
    public function calculates_points_for_very_slow_answer()
    {
        // Responder después del 75% del tiempo (11.25-15s de 15s): 100 + 0 = 100 puntos
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 14.0,
            'time_limit' => 15
        ]);

        $this->assertEquals(100, $points);
    }

    /** @test */
    public function works_with_different_time_limits()
    {
        // Con tiempo límite de 30 segundos
        // Primeros 25% = 0-7.5s: +50 bonus
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 5.0,
            'time_limit' => 30
        ]);

        $this->assertEquals(150, $points);
    }

    /** @test */
    public function supports_event_returns_true_for_valid_events()
    {
        $this->assertTrue($this->calculator->supportsEvent('correct_answer'));
    }

    /** @test */
    public function supports_event_returns_false_for_invalid_events()
    {
        $this->assertFalse($this->calculator->supportsEvent('invalid_event'));
    }

    /** @test */
    public function throws_exception_for_unsupported_event()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate('unsupported_event', []);
    }

    /** @test */
    public function throws_exception_if_missing_seconds_elapsed()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate('correct_answer', [
            'time_limit' => 15
        ]);
    }

    /** @test */
    public function throws_exception_if_missing_time_limit()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 5.0
        ]);
    }

    /** @test */
    public function get_config_returns_complete_configuration()
    {
        $config = $this->calculator->getConfig();

        $this->assertArrayHasKey('base_points', $config);
        $this->assertArrayHasKey('max_speed_bonus', $config);
        $this->assertArrayHasKey('speed_thresholds', $config);
        $this->assertArrayHasKey('supported_events', $config);

        $this->assertEquals(100, $config['base_points']);
        $this->assertEquals(50, $config['max_speed_bonus']);
        $this->assertIsArray($config['speed_thresholds']);
        $this->assertContains('correct_answer', $config['supported_events']);
    }

    /** @test */
    public function speed_threshold_boundaries_are_handled_correctly()
    {
        // Exactamente en el límite del 25% (3.75s de 15s)
        $points25 = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 3.75,
            'time_limit' => 15
        ]);
        $this->assertEquals(150, $points25); // Debe dar bonus de 50

        // Justo después del límite del 25%
        $pointsAfter25 = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 3.76,
            'time_limit' => 15
        ]);
        $this->assertEquals(130, $pointsAfter25); // Debe dar bonus de 30
    }
}

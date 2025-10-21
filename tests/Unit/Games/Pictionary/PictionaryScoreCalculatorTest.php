<?php

namespace Tests\Unit\Games\Pictionary;

use Games\Pictionary\PictionaryScoreCalculator;
use Tests\TestCase;

class PictionaryScoreCalculatorTest extends TestCase
{
    protected PictionaryScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PictionaryScoreCalculator();
    }

    /** @test */
    public function calculates_guesser_points_for_fast_answer()
    {
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 20,   // 20/90 = 22% (< 33%)
            'turn_duration' => 90,
        ]);

        $this->assertEquals(150, $points); // fast
    }

    /** @test */
    public function calculates_guesser_points_for_normal_answer()
    {
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 50,   // 50/90 = 55% (33% < x < 67%)
            'turn_duration' => 90,
        ]);

        $this->assertEquals(100, $points); // normal
    }

    /** @test */
    public function calculates_guesser_points_for_slow_answer()
    {
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 80,   // 80/90 = 88% (> 67%)
            'turn_duration' => 90,
        ]);

        $this->assertEquals(50, $points); // slow
    }

    /** @test */
    public function calculates_guesser_points_for_timeout()
    {
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 100,  // > 90 (timeout)
            'turn_duration' => 90,
        ]);

        $this->assertEquals(0, $points); // timeout
    }

    /** @test */
    public function calculates_drawer_bonus_for_fast_answer()
    {
        $points = $this->calculator->calculate('drawer_bonus', [
            'seconds_elapsed' => 25,
            'turn_duration' => 90,
        ]);

        $this->assertEquals(75, $points); // fast
    }

    /** @test */
    public function calculates_drawer_bonus_for_normal_answer()
    {
        $points = $this->calculator->calculate('drawer_bonus', [
            'seconds_elapsed' => 45,
            'turn_duration' => 90,
        ]);

        $this->assertEquals(50, $points); // normal
    }

    /** @test */
    public function calculates_drawer_bonus_for_slow_answer()
    {
        $points = $this->calculator->calculate('drawer_bonus', [
            'seconds_elapsed' => 75,
            'turn_duration' => 90,
        ]);

        $this->assertEquals(25, $points); // slow
    }

    /** @test */
    public function calculates_drawer_bonus_for_timeout()
    {
        $points = $this->calculator->calculate('drawer_bonus', [
            'seconds_elapsed' => 95,
            'turn_duration' => 90,
        ]);

        $this->assertEquals(0, $points); // timeout
    }

    /** @test */
    public function works_with_different_turn_durations()
    {
        // 60 segundos de duración
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 15,  // 15/60 = 25% (fast)
            'turn_duration' => 60,
        ]);
        $this->assertEquals(150, $points);

        // 120 segundos de duración
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 50,  // 50/120 = 41% (normal)
            'turn_duration' => 120,
        ]);
        $this->assertEquals(100, $points);
    }

    /** @test */
    public function supports_event_returns_true_for_valid_events()
    {
        $this->assertTrue($this->calculator->supportsEvent('correct_answer'));
        $this->assertTrue($this->calculator->supportsEvent('drawer_bonus'));
    }

    /** @test */
    public function supports_event_returns_false_for_invalid_events()
    {
        $this->assertFalse($this->calculator->supportsEvent('invalid_event'));
        $this->assertFalse($this->calculator->supportsEvent('round_win'));
    }

    /** @test */
    public function throws_exception_for_unsupported_event()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Evento 'invalid_event' no soportado");

        $this->calculator->calculate('invalid_event', []);
    }

    /** @test */
    public function throws_exception_if_missing_seconds_elapsed()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("El contexto requiere el campo 'seconds_elapsed'");

        $this->calculator->calculate('correct_answer', [
            'turn_duration' => 90,
        ]);
    }

    /** @test */
    public function throws_exception_if_missing_turn_duration()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("El contexto requiere el campo 'turn_duration'");

        $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 30,
        ]);
    }

    /** @test */
    public function get_config_returns_complete_configuration()
    {
        $config = $this->calculator->getConfig();

        $this->assertArrayHasKey('guesser_points', $config);
        $this->assertArrayHasKey('drawer_points', $config);
        $this->assertArrayHasKey('thresholds', $config);
        $this->assertArrayHasKey('supported_events', $config);
        $this->assertArrayHasKey('scoring_method', $config);

        $this->assertEquals('time_based', $config['scoring_method']);
        $this->assertContains('correct_answer', $config['supported_events']);
        $this->assertContains('drawer_bonus', $config['supported_events']);
    }

    /** @test */
    public function can_customize_guesser_points()
    {
        $this->calculator->setGuesserPoints([
            'fast' => 200,
            'normal' => 150,
            'slow' => 75,
        ]);

        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 20,
            'turn_duration' => 90,
        ]);

        $this->assertEquals(200, $points); // Customized fast points
    }

    /** @test */
    public function can_customize_drawer_points()
    {
        $this->calculator->setDrawerPoints([
            'fast' => 100,
            'normal' => 75,
            'slow' => 40,
        ]);

        $points = $this->calculator->calculate('drawer_bonus', [
            'seconds_elapsed' => 20,
            'turn_duration' => 90,
        ]);

        $this->assertEquals(100, $points); // Customized fast points
    }

    /** @test */
    public function can_customize_thresholds()
    {
        $calculator = new PictionaryScoreCalculator();
        $calculator->setThresholds([
            'fast' => 0.25,   // 25% instead of 33%
            'normal' => 0.75, // 75% instead of 67%
        ]);

        // 20 segundos de 90 = 22.2% (ahora es "fast" con threshold 25%)
        $points = $calculator->calculate('correct_answer', [
            'seconds_elapsed' => 20,
            'turn_duration' => 90,
        ]);

        $this->assertEquals(150, $points); // fast (con threshold customizado)

        // 70 segundos de 90 = 77.7% (ahora es "slow" con threshold 75%)
        $points = $calculator->calculate('correct_answer', [
            'seconds_elapsed' => 70,
            'turn_duration' => 90,
        ]);

        $this->assertEquals(50, $points); // slow (con threshold customizado)
    }

    /** @test */
    public function threshold_boundaries_are_handled_correctly()
    {
        // Exactamente en el límite fast (33% de 90 = 29.7)
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 29,
            'turn_duration' => 90,
        ]);
        $this->assertEquals(150, $points);

        // Justo después del límite fast
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 30,
            'turn_duration' => 90,
        ]);
        $this->assertEquals(100, $points);

        // Exactamente en el límite normal (67% de 90 = 60.3)
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 60,
            'turn_duration' => 90,
        ]);
        $this->assertEquals(100, $points);

        // Justo después del límite normal
        $points = $this->calculator->calculate('correct_answer', [
            'seconds_elapsed' => 61,
            'turn_duration' => 90,
        ]);
        $this->assertEquals(50, $points);
    }
}

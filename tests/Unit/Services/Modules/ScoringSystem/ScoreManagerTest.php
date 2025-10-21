<?php

namespace Tests\Unit\Services\Modules\ScoringSystem;

use App\Services\Modules\ScoringSystem\ScoreManager;
use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;
use Tests\TestCase;

class ScoreManagerTest extends TestCase
{
    protected ScoreCalculatorInterface $mockCalculator;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear mock del calculator
        $this->mockCalculator = $this->createMock(ScoreCalculatorInterface::class);
    }

    /** @test */
    public function can_initialize_with_players()
    {
        $manager = new ScoreManager([1, 2, 3], $this->mockCalculator);

        $scores = $manager->getScores();

        $this->assertCount(3, $scores);
        $this->assertEquals(0, $scores[1]);
        $this->assertEquals(0, $scores[2]);
        $this->assertEquals(0, $scores[3]);
    }

    /** @test */
    public function throws_exception_if_no_players()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Se requiere al menos un jugador');

        new ScoreManager([], $this->mockCalculator);
    }

    /** @test */
    public function can_award_points()
    {
        $this->mockCalculator
            ->method('calculate')
            ->willReturn(100);

        $manager = new ScoreManager([1, 2], $this->mockCalculator);

        $points = $manager->awardPoints(1, 'correct_answer', ['time' => 30]);

        $this->assertEquals(100, $points);
        $this->assertEquals(100, $manager->getScore(1));
        $this->assertEquals(0, $manager->getScore(2));
    }

    /** @test */
    public function accumulates_points_correctly()
    {
        $this->mockCalculator
            ->method('calculate')
            ->willReturnOnConsecutiveCalls(100, 50, 75);

        $manager = new ScoreManager([1], $this->mockCalculator);

        $manager->awardPoints(1, 'event1');
        $manager->awardPoints(1, 'event2');
        $manager->awardPoints(1, 'event3');

        $this->assertEquals(225, $manager->getScore(1));
    }

    /** @test */
    public function throws_exception_for_invalid_player()
    {
        $manager = new ScoreManager([1, 2], $this->mockCalculator);

        $this->expectException(\InvalidArgumentException::class);

        $manager->awardPoints(999, 'event');
    }

    /** @test */
    public function can_deduct_points()
    {
        $manager = new ScoreManager([1], $this->mockCalculator);
        $manager->setScore(1, 100);

        $newScore = $manager->deductPoints(1, 30);

        $this->assertEquals(70, $newScore);
        $this->assertEquals(70, $manager->getScore(1));
    }

    /** @test */
    public function prevents_negative_scores()
    {
        $manager = new ScoreManager([1], $this->mockCalculator);
        $manager->setScore(1, 50);

        $manager->deductPoints(1, 100);

        $this->assertEquals(0, $manager->getScore(1));
    }

    /** @test */
    public function can_set_score_directly()
    {
        $manager = new ScoreManager([1], $this->mockCalculator);

        $manager->setScore(1, 500);

        $this->assertEquals(500, $manager->getScore(1));
    }

    /** @test */
    public function can_get_ranking()
    {
        $manager = new ScoreManager([1, 2, 3], $this->mockCalculator);
        $manager->setScore(1, 100);
        $manager->setScore(2, 300);
        $manager->setScore(3, 200);

        $ranking = $manager->getRanking();

        $this->assertCount(3, $ranking);
        $this->assertEquals(1, $ranking[0]['position']);
        $this->assertEquals(2, $ranking[0]['player_id']);
        $this->assertEquals(300, $ranking[0]['score']);

        $this->assertEquals(2, $ranking[1]['position']);
        $this->assertEquals(3, $ranking[1]['player_id']);
        $this->assertEquals(200, $ranking[1]['score']);

        $this->assertEquals(3, $ranking[2]['position']);
        $this->assertEquals(1, $ranking[2]['player_id']);
        $this->assertEquals(100, $ranking[2]['score']);
    }

    /** @test */
    public function get_winner_returns_single_winner()
    {
        $manager = new ScoreManager([1, 2, 3], $this->mockCalculator);
        $manager->setScore(1, 100);
        $manager->setScore(2, 300);
        $manager->setScore(3, 200);

        $winner = $manager->getWinner();

        $this->assertNotNull($winner);
        $this->assertEquals(2, $winner['player_id']);
        $this->assertEquals(300, $winner['score']);
    }

    /** @test */
    public function get_winner_returns_null_on_tie()
    {
        $manager = new ScoreManager([1, 2, 3], $this->mockCalculator);
        $manager->setScore(1, 300);
        $manager->setScore(2, 300);
        $manager->setScore(3, 200);

        $winner = $manager->getWinner();

        $this->assertNull($winner); // Hay empate
    }

    /** @test */
    public function get_winners_returns_multiple_winners_on_tie()
    {
        $manager = new ScoreManager([1, 2, 3], $this->mockCalculator);
        $manager->setScore(1, 300);
        $manager->setScore(2, 300);
        $manager->setScore(3, 200);

        $winners = $manager->getWinners();

        $this->assertCount(2, $winners);
        $this->assertEquals(1, $winners[0]['player_id']);
        $this->assertEquals(2, $winners[1]['player_id']);
    }

    /** @test */
    public function calculates_statistics_correctly()
    {
        $manager = new ScoreManager([1, 2, 3], $this->mockCalculator);
        $manager->setScore(1, 100);
        $manager->setScore(2, 200);
        $manager->setScore(3, 150);

        $stats = $manager->getStatistics();

        $this->assertEquals(3, $stats['total_players']);
        $this->assertEquals(450, $stats['total_points']);
        $this->assertEquals(150, $stats['average_score']);
        $this->assertEquals(200, $stats['highest_score']);
        $this->assertEquals(100, $stats['lowest_score']);
    }

    /** @test */
    public function can_add_player_dynamically()
    {
        $manager = new ScoreManager([1, 2], $this->mockCalculator);

        $manager->addPlayer(3, 50);

        $this->assertEquals(50, $manager->getScore(3));
        $this->assertCount(3, $manager->getScores());
    }

    /** @test */
    public function throws_exception_when_adding_existing_player()
    {
        $manager = new ScoreManager([1], $this->mockCalculator);

        $this->expectException(\InvalidArgumentException::class);

        $manager->addPlayer(1);
    }

    /** @test */
    public function can_remove_player()
    {
        $manager = new ScoreManager([1, 2, 3], $this->mockCalculator);
        $manager->setScore(2, 150);

        $finalScore = $manager->removePlayer(2);

        $this->assertEquals(150, $finalScore);
        $this->assertCount(2, $manager->getScores());
        $this->assertArrayNotHasKey(2, $manager->getScores());
    }

    /** @test */
    public function can_reset_scores()
    {
        $manager = new ScoreManager([1, 2], $this->mockCalculator);
        $manager->setScore(1, 100);
        $manager->setScore(2, 200);

        $manager->reset();

        $this->assertEquals(0, $manager->getScore(1));
        $this->assertEquals(0, $manager->getScore(2));
    }

    /** @test */
    public function tracks_history_when_enabled()
    {
        $this->mockCalculator
            ->method('calculate')
            ->willReturn(100);

        $manager = new ScoreManager([1], $this->mockCalculator, trackHistory: true);

        $manager->awardPoints(1, 'correct_answer', ['time' => 30]);
        $manager->deductPoints(1, 20);

        $history = $manager->getHistory();

        $this->assertCount(2, $history);
        $this->assertEquals('correct_answer', $history[0]['event_type']);
        $this->assertEquals(100, $history[0]['points']);
        $this->assertEquals('penalty', $history[1]['event_type']);
        $this->assertEquals(-20, $history[1]['points']);
    }

    /** @test */
    public function does_not_track_history_when_disabled()
    {
        $manager = new ScoreManager([1], $this->mockCalculator, trackHistory: false);

        $manager->setScore(1, 100);
        $manager->deductPoints(1, 20);

        $history = $manager->getHistory();

        $this->assertEmpty($history);
    }

    /** @test */
    public function can_serialize_to_array()
    {
        $manager = new ScoreManager([1, 2], $this->mockCalculator);
        $manager->setScore(1, 100);
        $manager->setScore(2, 200);

        $data = $manager->toArray();

        $this->assertArrayHasKey('scores', $data);
        $this->assertEquals(100, $data['scores'][1]);
        $this->assertEquals(200, $data['scores'][2]);
    }

    /** @test */
    public function can_restore_from_array()
    {
        $data = [
            'scores' => [
                1 => 150,
                2 => 250,
            ],
        ];

        $manager = ScoreManager::fromArray([1, 2], $data, $this->mockCalculator);

        $this->assertEquals(150, $manager->getScore(1));
        $this->assertEquals(250, $manager->getScore(2));
    }

    /** @test */
    public function serialization_round_trip_preserves_state()
    {
        $this->mockCalculator
            ->method('calculate')
            ->willReturn(100);

        $manager1 = new ScoreManager([1, 2, 3], $this->mockCalculator, trackHistory: true);
        $manager1->awardPoints(1, 'event1');
        $manager1->setScore(2, 200);
        $manager1->setScore(3, 150);

        $data = $manager1->toArray();
        $manager2 = ScoreManager::fromArray([1, 2, 3], $data, $this->mockCalculator, trackHistory: true);

        $this->assertEquals($manager1->getScores(), $manager2->getScores());
        $this->assertEquals($manager1->getHistory(), $manager2->getHistory());
    }
}

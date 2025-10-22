<?php

namespace Tests\Unit\Services\Modules\RoundSystem;

use App\Services\Modules\RoundSystem\RoundManager;
use App\Services\Modules\TurnSystem\TurnManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests para RoundManager.
 *
 * Cobertura:
 * - Gestión de rondas
 * - Eliminación permanent/temporal
 * - Delegación a TurnManager
 * - Auto-limpieza de temporales
 * - Serialización
 */
class RoundManagerTest extends TestCase
{
    /**
     * Test: Puede crear con TurnManager y total de rondas.
     */
    public function test_can_create_with_turn_manager(): void
    {
        $turnManager = new TurnManager([1, 2, 3], mode: 'sequential');
        $roundManager = new RoundManager($turnManager, totalRounds: 5);

        $this->assertEquals(1, $roundManager->getCurrentRound());
        $this->assertEquals(5, $roundManager->getTotalRounds());
        $this->assertEquals(1, $roundManager->getCurrentPlayer());
    }

    /**
     * Test: nextTurn() delega a TurnManager.
     */
    public function test_next_turn_delegates_to_turn_manager(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager);

        $this->assertEquals(1, $roundManager->getCurrentPlayer());

        $roundManager->nextTurn();
        $this->assertEquals(2, $roundManager->getCurrentPlayer());

        $roundManager->nextTurn();
        $this->assertEquals(3, $roundManager->getCurrentPlayer());
    }

    /**
     * Test: Detecta cuando se completa una ronda.
     */
    public function test_detects_round_completion(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager);

        $this->assertEquals(1, $roundManager->getCurrentRound());
        $this->assertFalse($roundManager->isNewRound());

        $roundManager->nextTurn(); // 1 -> 2
        $this->assertFalse($roundManager->isNewRound());
        $this->assertEquals(1, $roundManager->getCurrentRound());

        $roundManager->nextTurn(); // 2 -> 3
        $this->assertFalse($roundManager->isNewRound());

        $roundManager->nextTurn(); // 3 -> 1 (nueva ronda)
        $this->assertTrue($roundManager->isNewRound());
        $this->assertEquals(2, $roundManager->getCurrentRound());
    }

    /**
     * Test: Juego termina cuando alcanza total de rondas.
     */
    public function test_game_completes_when_reaching_total_rounds(): void
    {
        $turnManager = new TurnManager([1, 2]);
        $roundManager = new RoundManager($turnManager, totalRounds: 2);

        $this->assertFalse($roundManager->isGameComplete());

        // Completar ronda 1
        $roundManager->nextTurn();
        $roundManager->nextTurn(); // Completa ronda 1, ahora en ronda 2
        $this->assertEquals(2, $roundManager->getCurrentRound());
        $this->assertFalse($roundManager->isGameComplete());

        // Completar ronda 2
        $roundManager->nextTurn();
        $roundManager->nextTurn(); // Completa ronda 2, ahora en ronda 3
        $this->assertEquals(3, $roundManager->getCurrentRound());
        $this->assertTrue($roundManager->isGameComplete());
    }

    /**
     * Test: Juego infinito nunca termina.
     */
    public function test_infinite_game_never_completes(): void
    {
        $turnManager = new TurnManager([1, 2]);
        $roundManager = new RoundManager($turnManager, totalRounds: 0);

        for ($i = 0; $i < 100; $i++) {
            $roundManager->nextTurn();
        }

        $this->assertFalse($roundManager->isGameComplete());
    }

    /**
     * Test: Puede eliminar jugador permanentemente.
     */
    public function test_can_eliminate_player_permanently(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager);

        $roundManager->eliminatePlayer(2, permanent: true);

        $this->assertTrue($roundManager->isEliminated(2));
        $this->assertTrue($roundManager->isPermanentlyEliminated(2));
        $this->assertEquals([1, 3], $roundManager->getActivePlayers());
        $this->assertEquals(2, $roundManager->getActivePlayerCount());
    }

    /**
     * Test: Puede eliminar jugador temporalmente.
     */
    public function test_can_eliminate_player_temporarily(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager);

        $roundManager->eliminatePlayer(2, permanent: false);

        $this->assertTrue($roundManager->isEliminated(2));
        $this->assertTrue($roundManager->isTemporarilyEliminated(2));
        $this->assertFalse($roundManager->isPermanentlyEliminated(2));
        $this->assertEquals([1, 3], $roundManager->getActivePlayers());
    }

    /**
     * Test: Auto-limpia temporales al completar ronda.
     */
    public function test_auto_clears_temporary_eliminations_on_round_complete(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager);

        $roundManager->eliminatePlayer(2, permanent: false);
        $this->assertTrue($roundManager->isEliminated(2));

        // Completar ronda
        $roundManager->nextTurn(); // 1 -> 2
        $roundManager->nextTurn(); // 2 -> 3
        $this->assertTrue($roundManager->isEliminated(2)); // Aún eliminado

        $roundManager->nextTurn(); // 3 -> 1 (nueva ronda)
        $this->assertFalse($roundManager->isEliminated(2)); // Restaurado!
        $this->assertEquals([1, 2, 3], $roundManager->getActivePlayers());
    }

    /**
     * Test: No limpia permanentes al completar ronda.
     */
    public function test_does_not_clear_permanent_eliminations_on_round_complete(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager);

        $roundManager->eliminatePlayer(2, permanent: true);

        // Completar múltiples rondas
        for ($i = 0; $i < 10; $i++) {
            $roundManager->nextTurn();
        }

        $this->assertTrue($roundManager->isPermanentlyEliminated(2));
        $this->assertEquals([1, 3], $roundManager->getActivePlayers());
    }

    /**
     * Test: Puede restaurar jugador temporal.
     */
    public function test_can_restore_temporary_player(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager);

        $roundManager->eliminatePlayer(2, permanent: false);
        $this->assertTrue($roundManager->isEliminated(2));

        $result = $roundManager->restorePlayer(2);
        $this->assertTrue($result);
        $this->assertFalse($roundManager->isEliminated(2));
    }

    /**
     * Test: No puede restaurar jugador permanente.
     */
    public function test_cannot_restore_permanent_player(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager);

        $roundManager->eliminatePlayer(2, permanent: true);

        $result = $roundManager->restorePlayer(2);
        $this->assertFalse($result);
        $this->assertTrue($roundManager->isPermanentlyEliminated(2));
    }

    /**
     * Test: Delegación correcta de métodos de TurnManager.
     */
    public function test_delegates_turn_manager_methods(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager);

        $this->assertEquals([1, 2, 3], $roundManager->getTurnOrder());
        $this->assertTrue($roundManager->isPlayerTurn(1));
        $this->assertFalse($roundManager->isPlayerTurn(2));

        $roundManager->pause();
        $this->assertTrue($roundManager->isPaused());

        $roundManager->resume();
        $this->assertFalse($roundManager->isPaused());
    }

    /**
     * Test: Serializa correctamente.
     */
    public function test_serializes_correctly(): void
    {
        $turnManager = new TurnManager([1, 2, 3]);
        $roundManager = new RoundManager($turnManager, totalRounds: 5, currentRound: 2);

        $roundManager->eliminatePlayer(1, permanent: true);
        $roundManager->eliminatePlayer(2, permanent: false);

        $data = $roundManager->toArray();

        $this->assertEquals(2, $data['current_round']);
        $this->assertEquals(5, $data['total_rounds']);
        $this->assertEquals([1], $data['permanently_eliminated']);
        $this->assertEquals([2], $data['temporarily_eliminated']);
        $this->assertArrayHasKey('turn_system', $data);
    }

    /**
     * Test: Restaura desde array correctamente.
     */
    public function test_restores_from_array(): void
    {
        $data = [
            'current_round' => 3,
            'total_rounds' => 5,
            'permanently_eliminated' => [1],
            'temporarily_eliminated' => [2],
            'turn_system' => [
                'turn_order' => [1, 2, 3],
                'current_turn_index' => 1,
                'mode' => 'sequential',
                'is_paused' => false,
                'direction' => 1,
            ],
        ];

        $roundManager = RoundManager::fromArray($data);

        $this->assertEquals(3, $roundManager->getCurrentRound());
        $this->assertEquals(5, $roundManager->getTotalRounds());
        $this->assertTrue($roundManager->isPermanentlyEliminated(1));
        $this->assertTrue($roundManager->isTemporarilyEliminated(2));
        $this->assertEquals(2, $roundManager->getCurrentPlayer());
    }

    /**
     * Test: Serialización round-trip mantiene estado.
     */
    public function test_serialization_roundtrip(): void
    {
        $turnManager = new TurnManager([1, 2, 3, 4]);
        $roundManager = new RoundManager($turnManager, totalRounds: 3);

        $roundManager->eliminatePlayer(1, permanent: true);
        $roundManager->eliminatePlayer(3, permanent: false);
        $roundManager->nextTurn();
        $roundManager->nextTurn();

        $data = $roundManager->toArray();
        $restored = RoundManager::fromArray($data);

        $this->assertEquals($roundManager->getCurrentRound(), $restored->getCurrentRound());
        $this->assertEquals($roundManager->getTotalRounds(), $restored->getTotalRounds());
        $this->assertEquals($roundManager->getPermanentlyEliminated(), $restored->getPermanentlyEliminated());
        $this->assertEquals($roundManager->getTemporarilyEliminated(), $restored->getTemporarilyEliminated());
        $this->assertEquals($roundManager->getCurrentPlayer(), $restored->getCurrentPlayer());
        $this->assertEquals($roundManager->getActivePlayers(), $restored->getActivePlayers());
    }
}

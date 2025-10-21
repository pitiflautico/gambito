<?php

namespace Tests\Unit\Services\Modules\TurnSystem;

use App\Services\Modules\TurnSystem\TurnManager;
use Tests\TestCase;

class TurnManagerTest extends TestCase
{
    // ========================================================================
    // Tests de Inicialización
    // ========================================================================

    public function test_can_initialize_with_sequential_mode()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $this->assertEquals([1, 2, 3], $turnManager->getTurnOrder());
        $this->assertEquals(1, $turnManager->getCurrentPlayer());
        $this->assertEquals('sequential', $turnManager->getMode());
    }

    public function test_can_initialize_with_shuffle_mode()
    {
        $turnManager = new TurnManager([1, 2, 3], 'shuffle');

        // El orden debe ser diferente (aleatorio), pero contener los mismos IDs
        $order = $turnManager->getTurnOrder();
        $this->assertCount(3, $order);
        $this->assertContains(1, $order);
        $this->assertContains(2, $order);
        $this->assertContains(3, $order);
    }

    public function test_throws_exception_if_no_players()
    {
        $this->expectException(\InvalidArgumentException::class);
        new TurnManager([]);
    }

    public function test_can_set_total_rounds()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential', totalRounds: 5);

        $this->assertEquals(1, $turnManager->getCurrentRound());
        $this->assertFalse($turnManager->isGameComplete());
    }

    // ========================================================================
    // Tests de Avance de Turnos
    // ========================================================================

    public function test_next_turn_advances_to_next_player()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $info = $turnManager->nextTurn();

        $this->assertEquals(2, $info['player_id']);
        $this->assertEquals(1, $info['turn_index']);
        $this->assertEquals(1, $info['round']);
        $this->assertFalse($info['round_completed']);
    }

    public function test_completing_round_increments_round_number()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        // Completar ronda (3 turnos)
        $turnManager->nextTurn(); // Jugador 2
        $turnManager->nextTurn(); // Jugador 3
        $info = $turnManager->nextTurn(); // Vuelve a Jugador 1

        $this->assertEquals(1, $info['player_id']);
        $this->assertEquals(0, $info['turn_index']);
        $this->assertEquals(2, $info['round']);
        $this->assertTrue($info['round_completed']);
        $this->assertTrue($turnManager->isNewRound());
    }

    public function test_circular_rotation_works_correctly()
    {
        $turnManager = new TurnManager([10, 20, 30], 'sequential');

        $this->assertEquals(10, $turnManager->getCurrentPlayer());
        $turnManager->nextTurn();
        $this->assertEquals(20, $turnManager->getCurrentPlayer());
        $turnManager->nextTurn();
        $this->assertEquals(30, $turnManager->getCurrentPlayer());
        $turnManager->nextTurn();
        $this->assertEquals(10, $turnManager->getCurrentPlayer()); // Vuelve al primero
    }

    // ========================================================================
    // Tests de Consultas
    // ========================================================================

    public function test_is_player_turn_returns_correct_value()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $this->assertTrue($turnManager->isPlayerTurn(1));
        $this->assertFalse($turnManager->isPlayerTurn(2));
        $this->assertFalse($turnManager->isPlayerTurn(3));

        $turnManager->nextTurn();

        $this->assertFalse($turnManager->isPlayerTurn(1));
        $this->assertTrue($turnManager->isPlayerTurn(2));
        $this->assertFalse($turnManager->isPlayerTurn(3));
    }

    public function test_peek_next_player_does_not_advance_turn()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $nextPlayer = $turnManager->peekNextPlayer();

        $this->assertEquals(2, $nextPlayer);
        $this->assertEquals(1, $turnManager->getCurrentPlayer()); // No cambió
        $this->assertEquals(0, $turnManager->getCurrentTurnIndex()); // No cambió
    }

    public function test_get_player_count_returns_correct_number()
    {
        $turnManager = new TurnManager([1, 2, 3, 4], 'sequential');

        $this->assertEquals(4, $turnManager->getPlayerCount());
    }

    public function test_get_current_turn_info_returns_complete_data()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential', totalRounds: 5);

        $info = $turnManager->getCurrentTurnInfo();

        $this->assertArrayHasKey('player_id', $info);
        $this->assertArrayHasKey('turn_index', $info);
        $this->assertArrayHasKey('round', $info);
        $this->assertArrayHasKey('round_completed', $info);
        $this->assertArrayHasKey('game_complete', $info);

        $this->assertEquals(1, $info['player_id']);
        $this->assertEquals(0, $info['turn_index']);
        $this->assertEquals(1, $info['round']);
        $this->assertFalse($info['round_completed']);
        $this->assertFalse($info['game_complete']);
    }

    // ========================================================================
    // Tests de Fin de Juego
    // ========================================================================

    public function test_is_game_complete_returns_false_when_rounds_remaining()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential', totalRounds: 5);

        $this->assertFalse($turnManager->isGameComplete());
    }

    public function test_is_game_complete_returns_true_when_all_rounds_finished()
    {
        $turnManager = new TurnManager([1, 2], 'sequential', totalRounds: 2);

        // Ronda 1
        $turnManager->nextTurn(); // Jugador 2
        $turnManager->nextTurn(); // Jugador 1, ronda 2

        // Ronda 2
        $turnManager->nextTurn(); // Jugador 2
        $turnManager->nextTurn(); // Jugador 1, ronda 3 (excede el total)

        $this->assertTrue($turnManager->isGameComplete());
    }

    public function test_unlimited_rounds_never_complete()
    {
        $turnManager = new TurnManager([1, 2], 'sequential', totalRounds: 0); // 0 = infinitas

        // Avanzar 100 rondas
        for ($i = 0; $i < 200; $i++) {
            $turnManager->nextTurn();
        }

        $this->assertFalse($turnManager->isGameComplete());
    }

    // ========================================================================
    // Tests de Gestión de Jugadores
    // ========================================================================

    public function test_can_remove_player()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $removed = $turnManager->removePlayer(2);

        $this->assertTrue($removed);
        $this->assertEquals([1, 3], $turnManager->getTurnOrder());
        $this->assertEquals(2, $turnManager->getPlayerCount());
    }

    public function test_remove_player_adjusts_current_index_if_needed()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->nextTurn(); // Ahora es el turno del jugador 2 (índice 1)
        $this->assertEquals(2, $turnManager->getCurrentPlayer());

        // Eliminar jugador 1 (antes del actual)
        $turnManager->removePlayer(1);

        // El índice debería ajustarse
        $this->assertEquals(2, $turnManager->getCurrentPlayer()); // Sigue siendo jugador 2
        $this->assertEquals(0, $turnManager->getCurrentTurnIndex()); // Pero ahora índice 0
    }

    public function test_remove_nonexistent_player_returns_false()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $removed = $turnManager->removePlayer(999);

        $this->assertFalse($removed);
        $this->assertEquals(3, $turnManager->getPlayerCount());
    }

    public function test_can_add_player()
    {
        $turnManager = new TurnManager([1, 2], 'sequential');

        $turnManager->addPlayer(3);

        $this->assertEquals([1, 2, 3], $turnManager->getTurnOrder());
        $this->assertEquals(3, $turnManager->getPlayerCount());
    }

    public function test_added_player_is_placed_at_end()
    {
        $turnManager = new TurnManager([1, 2], 'sequential');

        $turnManager->addPlayer(3);
        $turnManager->addPlayer(4);

        $order = $turnManager->getTurnOrder();

        $this->assertEquals(3, $order[2]);
        $this->assertEquals(4, $order[3]);
    }

    // ========================================================================
    // Tests de Reset
    // ========================================================================

    public function test_reset_returns_to_initial_state()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential', totalRounds: 5);

        // Avanzar varios turnos
        $turnManager->nextTurn();
        $turnManager->nextTurn();
        $turnManager->nextTurn(); // Nueva ronda

        $this->assertEquals(2, $turnManager->getCurrentRound());
        $this->assertEquals(0, $turnManager->getCurrentTurnIndex());

        // Reset
        $turnManager->reset();

        $this->assertEquals(1, $turnManager->getCurrentRound());
        $this->assertEquals(0, $turnManager->getCurrentTurnIndex());
        $this->assertEquals(1, $turnManager->getCurrentPlayer());
    }

    // ========================================================================
    // Tests de Serialización
    // ========================================================================

    public function test_to_array_exports_complete_state()
    {
        $turnManager = new TurnManager([1, 2, 3], 'shuffle', totalRounds: 5);

        $state = $turnManager->toArray();

        $this->assertArrayHasKey('turn_order', $state);
        $this->assertArrayHasKey('current_turn_index', $state);
        $this->assertArrayHasKey('current_round', $state);
        $this->assertArrayHasKey('total_rounds', $state);
        $this->assertArrayHasKey('mode', $state);
        $this->assertArrayHasKey('is_paused', $state);
        $this->assertArrayHasKey('direction', $state);

        $this->assertEquals(0, $state['current_turn_index']);
        $this->assertEquals(1, $state['current_round']);
        $this->assertEquals(5, $state['total_rounds']);
        $this->assertEquals('shuffle', $state['mode']);
        $this->assertFalse($state['is_paused']);
        $this->assertEquals(1, $state['direction']);
    }

    public function test_from_array_restores_state_correctly()
    {
        $state = [
            'turn_order' => [10, 20, 30],
            'current_turn_index' => 2,
            'current_round' => 3,
            'total_rounds' => 5,
            'mode' => 'sequential',
            'is_paused' => false,
            'direction' => 1,
        ];

        $turnManager = TurnManager::fromArray($state);

        $this->assertEquals([10, 20, 30], $turnManager->getTurnOrder());
        $this->assertEquals(2, $turnManager->getCurrentTurnIndex());
        $this->assertEquals(30, $turnManager->getCurrentPlayer());
        $this->assertEquals(3, $turnManager->getCurrentRound());
        $this->assertEquals('sequential', $turnManager->getMode());
        $this->assertFalse($turnManager->isPaused());
        $this->assertEquals(1, $turnManager->getDirection());
    }

    public function test_serialization_round_trip_preserves_state()
    {
        $original = new TurnManager([1, 2, 3], 'sequential', totalRounds: 5);

        $original->nextTurn();
        $original->nextTurn();

        $state = $original->toArray();
        $restored = TurnManager::fromArray($state);

        $this->assertEquals($original->getCurrentPlayer(), $restored->getCurrentPlayer());
        $this->assertEquals($original->getCurrentTurnIndex(), $restored->getCurrentTurnIndex());
        $this->assertEquals($original->getCurrentRound(), $restored->getCurrentRound());
        $this->assertEquals($original->getTurnOrder(), $restored->getTurnOrder());
    }

    // ========================================================================
    // Tests de Nuevas Features: Pause/Resume
    // ========================================================================

    public function test_pause_prevents_turn_advancement()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        $turnManager->pause();
        $this->assertTrue($turnManager->isPaused());

        $turnManager->nextTurn(); // No debería avanzar

        $this->assertEquals(1, $turnManager->getCurrentPlayer()); // Sigue siendo el mismo
        $this->assertEquals(0, $turnManager->getCurrentTurnIndex());
    }

    public function test_resume_allows_turn_advancement()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->pause();
        $turnManager->nextTurn(); // No avanza
        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        $turnManager->resume();
        $this->assertFalse($turnManager->isPaused());

        $turnManager->nextTurn(); // Ahora sí avanza

        $this->assertEquals(2, $turnManager->getCurrentPlayer());
    }

    // ========================================================================
    // Tests de Nuevas Features: Reverse
    // ========================================================================

    public function test_reverse_changes_direction()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $this->assertEquals(1, $turnManager->getDirection());
        $this->assertTrue($turnManager->isForward());

        $turnManager->reverse();

        $this->assertEquals(-1, $turnManager->getDirection());
        $this->assertFalse($turnManager->isForward());
    }

    public function test_reverse_order_works_correctly()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        // Avanzar normalmente
        $turnManager->nextTurn();
        $this->assertEquals(2, $turnManager->getCurrentPlayer());

        // Invertir dirección
        $turnManager->reverse();

        // Debería volver al jugador anterior
        $turnManager->nextTurn();
        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        // Continuar en reversa
        $turnManager->nextTurn();
        $this->assertEquals(3, $turnManager->getCurrentPlayer()); // Último jugador
    }

    public function test_reverse_increments_round_when_reaching_start()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->reverse();

        // Estamos en jugador 1 (índice 0), ir en reversa debería ir al último
        $info = $turnManager->nextTurn();

        $this->assertEquals(3, $info['player_id']);
        $this->assertEquals(2, $info['turn_index']);
        $this->assertEquals(2, $info['round']); // Nueva ronda
        $this->assertTrue($info['round_completed']);
    }

    public function test_double_reverse_returns_to_normal()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->reverse();
        $this->assertEquals(-1, $turnManager->getDirection());

        $turnManager->reverse();
        $this->assertEquals(1, $turnManager->getDirection());

        // Comportamiento normal
        $turnManager->nextTurn();
        $this->assertEquals(2, $turnManager->getCurrentPlayer());
    }

    // ========================================================================
    // Tests de Nuevas Features: Skip Turn
    // ========================================================================

    public function test_skip_turn_advances_to_next_player()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        $info = $turnManager->skipTurn();

        $this->assertEquals(2, $info['player_id']);
        $this->assertEquals(1, $info['turn_index']);
    }

    public function test_skip_player_turn_skips_if_its_their_turn()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        $skipped = $turnManager->skipPlayerTurn(1); // Es el turno del jugador 1

        $this->assertTrue($skipped);
        $this->assertEquals(2, $turnManager->getCurrentPlayer()); // Avanzó al siguiente
    }

    public function test_skip_player_turn_returns_false_if_not_their_turn()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        $skipped = $turnManager->skipPlayerTurn(2); // No es el turno del jugador 2

        $this->assertFalse($skipped);
        $this->assertEquals(1, $turnManager->getCurrentPlayer()); // No cambió
    }

    // ========================================================================
    // Tests de Peek con Dirección
    // ========================================================================

    public function test_peek_next_player_respects_direction()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        // Normal (forward)
        $this->assertEquals(2, $turnManager->peekNextPlayer());

        // Reversa
        $turnManager->reverse();
        $this->assertEquals(3, $turnManager->peekNextPlayer()); // Va al último
    }

    // ========================================================================
    // Tests de Edge Cases
    // ========================================================================

    public function test_single_player_can_play()
    {
        $turnManager = new TurnManager([1], 'sequential', totalRounds: 3);

        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        $turnManager->nextTurn();
        $this->assertEquals(1, $turnManager->getCurrentPlayer());
        $this->assertEquals(2, $turnManager->getCurrentRound());

        $turnManager->nextTurn();
        $this->assertEquals(3, $turnManager->getCurrentRound());

        $turnManager->nextTurn();
        $this->assertTrue($turnManager->isGameComplete());
    }

    public function test_removing_current_player_adjusts_correctly()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->nextTurn(); // Turno de jugador 2 (índice 1)

        $this->assertEquals(2, $turnManager->getCurrentPlayer());

        // Eliminar el jugador actual
        $turnManager->removePlayer(2);

        // Debería ajustar al siguiente jugador disponible
        $this->assertContains($turnManager->getCurrentPlayer(), [1, 3]);
    }
}

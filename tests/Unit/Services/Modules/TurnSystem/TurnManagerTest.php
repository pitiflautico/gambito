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

    // ELIMINADO: TurnManager ya no gestiona rondas (ahora es responsabilidad de RoundManager)

    // ========================================================================
    // Tests de Avance de Turnos
    // ========================================================================

    public function test_next_turn_advances_to_next_player()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $info = $turnManager->nextTurn();

        $this->assertEquals(2, $info['player_id']);
        $this->assertEquals(1, $info['turn_index']);
        $this->assertFalse($info['cycle_completed']);
    }

    public function test_completing_cycle_is_detected()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        // Completar ciclo (3 turnos)
        $turnManager->nextTurn(); // Jugador 2
        $turnManager->nextTurn(); // Jugador 3
        $info = $turnManager->nextTurn(); // Vuelve a Jugador 1

        $this->assertEquals(1, $info['player_id']);
        $this->assertEquals(0, $info['turn_index']);
        $this->assertTrue($info['cycle_completed']);
        $this->assertTrue($turnManager->isCycleComplete());
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
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $info = $turnManager->getCurrentTurnInfo();

        $this->assertArrayHasKey('player_id', $info);
        $this->assertArrayHasKey('turn_index', $info);
        $this->assertArrayHasKey('cycle_completed', $info);

        $this->assertEquals(1, $info['player_id']);
        $this->assertEquals(0, $info['turn_index']);
        $this->assertFalse($info['cycle_completed']);
    }

    // ========================================================================
    // Tests de Fin de Juego
    // ========================================================================

    // ELIMINADO: isGameComplete() ahora es responsabilidad de RoundManager
    // TurnManager solo gestiona turnos, no fin de juego por rondas

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
        $turnManager->nextTurn(); // Ahora es el turno del jugador 3 (índice 2)
        $this->assertEquals(3, $turnManager->getCurrentPlayer());

        // Eliminar jugador 3 (el actual)
        $turnManager->removePlayer(3);

        // El índice debería ajustarse (volver al inicio si excede)
        $this->assertEquals([1, 2], $turnManager->getTurnOrder());
        $this->assertEquals(0, $turnManager->getCurrentTurnIndex());
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
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        // Avanzar varios turnos
        $turnManager->nextTurn();
        $turnManager->nextTurn();
        $turnManager->nextTurn(); // Completa ciclo

        $this->assertEquals(0, $turnManager->getCurrentTurnIndex());
        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        // Avanzar más
        $turnManager->nextTurn();
        $this->assertEquals(2, $turnManager->getCurrentPlayer());

        // Reset
        $turnManager->reset();

        $this->assertEquals(0, $turnManager->getCurrentTurnIndex());
        $this->assertEquals(1, $turnManager->getCurrentPlayer());
        $this->assertFalse($turnManager->isPaused());
        $this->assertEquals(1, $turnManager->getDirection());
    }

    // ========================================================================
    // Tests de Serialización
    // ========================================================================

    public function test_to_array_exports_complete_state()
    {
        $turnManager = new TurnManager([1, 2, 3], 'shuffle');

        $state = $turnManager->toArray();

        $this->assertArrayHasKey('turn_order', $state);
        $this->assertArrayHasKey('current_turn_index', $state);
        $this->assertArrayHasKey('mode', $state);
        $this->assertArrayHasKey('is_paused', $state);
        $this->assertArrayHasKey('direction', $state);

        $this->assertEquals(0, $state['current_turn_index']);
        $this->assertEquals('shuffle', $state['mode']);
        $this->assertFalse($state['is_paused']);
        $this->assertEquals(1, $state['direction']);
    }

    public function test_from_array_restores_state_correctly()
    {
        $state = [
            'turn_order' => [10, 20, 30],
            'current_turn_index' => 2,
            'mode' => 'sequential',
            'is_paused' => false,
            'direction' => 1,
        ];

        $turnManager = TurnManager::fromArray($state);

        $this->assertEquals([10, 20, 30], $turnManager->getTurnOrder());
        $this->assertEquals(2, $turnManager->getCurrentTurnIndex());
        $this->assertEquals(30, $turnManager->getCurrentPlayer());
        $this->assertEquals('sequential', $turnManager->getMode());
        $this->assertFalse($turnManager->isPaused());
        $this->assertEquals(1, $turnManager->getDirection());
    }

    public function test_serialization_round_trip_preserves_state()
    {
        $original = new TurnManager([1, 2, 3], 'sequential');

        $original->nextTurn();
        $original->nextTurn();

        $state = $original->toArray();
        $restored = TurnManager::fromArray($state);

        $this->assertEquals($original->getCurrentPlayer(), $restored->getCurrentPlayer());
        $this->assertEquals($original->getCurrentTurnIndex(), $restored->getCurrentTurnIndex());
        $this->assertEquals($original->getTurnOrder(), $restored->getTurnOrder());
        $this->assertEquals($original->getMode(), $restored->getMode());
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

    public function test_reverse_completes_cycle_when_reaching_start()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->reverse();

        // Estamos en jugador 1 (índice 0), ir en reversa debería ir al último
        $info = $turnManager->nextTurn();

        $this->assertEquals(3, $info['player_id']);
        $this->assertEquals(2, $info['turn_index']);
        $this->assertTrue($info['cycle_completed']);
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
        $turnManager = new TurnManager([1], 'sequential');

        $this->assertEquals(1, $turnManager->getCurrentPlayer());

        $info = $turnManager->nextTurn();
        $this->assertEquals(1, $info['player_id']);
        $this->assertTrue($info['cycle_completed']); // Un jugador completa ciclo inmediatamente

        $info = $turnManager->nextTurn();
        $this->assertEquals(1, $info['player_id']);
        $this->assertTrue($info['cycle_completed']);
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

    // ========================================================================
    // Tests de Tracking de Completions (para equipos)
    // ========================================================================

    public function test_can_mark_player_completed()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->markPlayerCompleted(1);

        $this->assertTrue($turnManager->hasPlayerCompleted(1));
        $this->assertFalse($turnManager->hasPlayerCompleted(2));
        $this->assertFalse($turnManager->hasPlayerCompleted(3));
    }

    public function test_can_mark_multiple_players_completed()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->markPlayerCompleted(1);
        $turnManager->markPlayerCompleted(3);

        $this->assertTrue($turnManager->hasPlayerCompleted(1));
        $this->assertFalse($turnManager->hasPlayerCompleted(2));
        $this->assertTrue($turnManager->hasPlayerCompleted(3));
    }

    public function test_get_completed_players_returns_correct_list()
    {
        $turnManager = new TurnManager([1, 2, 3, 4], 'sequential');

        $turnManager->markPlayerCompleted(1);
        $turnManager->markPlayerCompleted(3);
        $turnManager->markPlayerCompleted(4);

        $completed = $turnManager->getCompletedPlayers();

        $this->assertCount(3, $completed);
        $this->assertContains(1, $completed);
        $this->assertContains(3, $completed);
        $this->assertContains(4, $completed);
        $this->assertNotContains(2, $completed);
    }

    public function test_clear_completions_removes_all_completions()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->markPlayerCompleted(1);
        $turnManager->markPlayerCompleted(2);

        $this->assertCount(2, $turnManager->getCompletedPlayers());

        $turnManager->clearCompletions();

        $this->assertCount(0, $turnManager->getCompletedPlayers());
        $this->assertFalse($turnManager->hasPlayerCompleted(1));
        $this->assertFalse($turnManager->hasPlayerCompleted(2));
    }

    public function test_next_turn_clears_completions_automatically()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->markPlayerCompleted(1);
        $this->assertCount(1, $turnManager->getCompletedPlayers());

        // Avanzar turno debería limpiar completions
        $turnManager->nextTurn();

        $this->assertCount(0, $turnManager->getCompletedPlayers());
        $this->assertFalse($turnManager->hasPlayerCompleted(1));
    }

    public function test_is_turn_complete_returns_true_for_individual_mode()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        // Sin equipos, el turno siempre está completo
        $status = $turnManager->isTurnComplete();

        $this->assertTrue($status['is_complete']);
        $this->assertEquals('individual_turn', $status['reason']);
        $this->assertEquals(1, $status['completed_count']);
        $this->assertEquals(1, $status['total_count']);
    }

    public function test_can_advance_turn_returns_true_when_not_paused()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $status = $turnManager->canAdvanceTurn();

        $this->assertTrue($status['can_advance']);
        $this->assertEquals('turn_complete', $status['reason']);
    }

    public function test_can_advance_turn_returns_false_when_paused()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->pause();

        $status = $turnManager->canAdvanceTurn();

        $this->assertFalse($status['can_advance']);
        $this->assertEquals('paused', $status['reason']);
    }

    public function test_set_require_all_team_members_changes_setting()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        // Verificar que inicia en false
        $state = $turnManager->toArray();
        $this->assertFalse($state['require_all_team_members']);

        $turnManager->setRequireAllTeamMembers(true);

        $state = $turnManager->toArray();
        $this->assertTrue($state['require_all_team_members']);
    }

    // ========================================================================
    // Tests de Serialización con Nuevos Campos
    // ========================================================================

    public function test_to_array_includes_turn_completions()
    {
        $turnManager = new TurnManager([1, 2, 3], 'sequential');

        $turnManager->markPlayerCompleted(1);
        $turnManager->markPlayerCompleted(2);

        $state = $turnManager->toArray();

        $this->assertArrayHasKey('turn_completions', $state);
        $this->assertArrayHasKey('require_all_team_members', $state);
        $this->assertCount(2, $state['turn_completions']);
    }

    public function test_from_array_restores_turn_completions()
    {
        $state = [
            'turn_order' => [1, 2, 3],
            'current_turn_index' => 0,
            'mode' => 'sequential',
            'is_paused' => false,
            'direction' => 1,
            'turn_completions' => [1 => true, 3 => true],
            'require_all_team_members' => true,
        ];

        $turnManager = TurnManager::fromArray($state);

        $this->assertTrue($turnManager->hasPlayerCompleted(1));
        $this->assertFalse($turnManager->hasPlayerCompleted(2));
        $this->assertTrue($turnManager->hasPlayerCompleted(3));
        $this->assertEquals(true, $turnManager->toArray()['require_all_team_members']);
    }

    public function test_serialization_with_completions_preserves_state()
    {
        $original = new TurnManager([1, 2, 3, 4], 'sequential');

        $original->markPlayerCompleted(1);
        $original->markPlayerCompleted(3);
        $original->setRequireAllTeamMembers(true);

        $state = $original->toArray();
        $restored = TurnManager::fromArray($state);

        $this->assertEquals($original->getCompletedPlayers(), $restored->getCompletedPlayers());
        $this->assertEquals(
            $original->toArray()['require_all_team_members'],
            $restored->toArray()['require_all_team_members']
        );
    }
}

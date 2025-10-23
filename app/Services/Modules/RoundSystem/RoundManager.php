<?php

namespace App\Services\Modules\RoundSystem;

use App\Services\Modules\TurnSystem\TurnManager;
use App\Services\Modules\TeamsSystem\TeamsManager;

/**
 * Servicio genérico para gestionar rondas en juegos.
 *
 * Una RONDA es un ciclo completo donde todos los jugadores han participado.
 * RoundManager gestiona:
 * - Conteo de rondas (actual, total)
 * - Eliminación de jugadores (permanente/temporal por ronda)
 * - Detección de fin de juego
 * - Contiene un TurnManager para gestionar turnos dentro de la ronda
 *
 * Separación de responsabilidades:
 * - RoundManager: ¿En qué ronda estamos? ¿Quién está eliminado? ¿Acabó el juego?
 * - TurnManager: ¿De quién es el turno ahora? ¿En qué orden juegan?
 *
 * Ejemplos de uso:
 * - Pictionary: 5 rondas, eliminación temporal por ronda
 * - Battle Royale: Rondas infinitas, eliminación permanente
 * - Trivia: N rondas, sin eliminación
 * - Mafia: Múltiples rondas día/noche, eliminación permanente
 */
class RoundManager
{
    /**
     * Ronda actual (1-based).
     */
    protected int $currentRound;

    /**
     * Total de rondas del juego (0 = infinitas).
     */
    protected int $totalRounds;

    /**
     * Jugadores eliminados permanentemente (fuera del juego).
     *
     * @var array<int>
     */
    protected array $permanentlyEliminated = [];

    /**
     * Jugadores eliminados temporalmente (solo esta ronda).
     *
     * @var array<int>
     */
    protected array $temporarilyEliminated = [];

    /**
     * TurnManager interno para gestionar turnos.
     */
    protected TurnManager $turnManager;

    /**
     * TeamsManager opcional para juegos por equipos.
     */
    protected ?TeamsManager $teamsManager = null;

    /**
     * Si se acaba de completar una ronda.
     */
    protected bool $roundJustCompleted = false;

    /**
     * Constructor.
     *
     * @param TurnManager $turnManager Gestor de turnos
     * @param int $totalRounds Total de rondas (0 = infinitas)
     * @param int $currentRound Ronda inicial
     */
    public function __construct(
        TurnManager $turnManager,
        int $totalRounds = 0,
        int $currentRound = 1
    ) {
        $this->turnManager = $turnManager;
        $this->totalRounds = $totalRounds;
        $this->currentRound = $currentRound;
    }

    /**
     * Avanzar al siguiente turno.
     *
     * Delega al TurnManager y detecta cuando se completa una ronda.
     *
     * @return array Info del turno actual
     */
    public function nextTurn(): array
    {
        $this->roundJustCompleted = false;

        // Delegar al TurnManager
        $turnInfo = $this->turnManager->nextTurn();

        // Detectar si completó un ciclo (= completó una ronda)
        if ($this->turnManager->isCycleComplete()) {
            $this->currentRound++;
            $this->roundJustCompleted = true;

            // Auto-limpiar eliminaciones temporales
            $this->clearTemporaryEliminations();
        }

        return $turnInfo;
    }

    /**
     * Verificar si se acaba de completar una ronda.
     *
     * @return bool True si el último nextTurn() completó una ronda
     */
    public function isNewRound(): bool
    {
        return $this->roundJustCompleted;
    }

    /**
     * Obtener la ronda actual.
     *
     * @return int Ronda actual (1-based)
     */
    public function getCurrentRound(): int
    {
        return $this->currentRound;
    }

    /**
     * Obtener total de rondas.
     *
     * @return int Total de rondas (0 = infinitas)
     */
    public function getTotalRounds(): int
    {
        return $this->totalRounds;
    }

    /**
     * Verificar si el juego ha terminado.
     *
     * El juego termina cuando:
     * 1. Se alcanzó el total de rondas (si totalRounds > 0)
     * 2. O cuando cada juego decide (ej. Battle Royale: solo 1 jugador activo)
     *
     * Nota: Esta función solo verifica rondas. El game engine puede
     * agregar lógica adicional (ej. verificar jugadores activos).
     *
     * @return bool True si se completaron todas las rondas
     */
    public function isGameComplete(): bool
    {
        if ($this->totalRounds === 0) {
            return false; // Juego infinito
        }

        return $this->currentRound > $this->totalRounds;
    }

    // ========================================================================
    // GESTIÓN DE ELIMINACIONES
    // ========================================================================

    /**
     * Eliminar un jugador del juego.
     *
     * @param int $playerId ID del jugador
     * @param bool $permanent True = permanente, False = solo esta ronda
     * @return void
     */
    public function eliminatePlayer(int $playerId, bool $permanent = true): void
    {
        // Verificar que el jugador existe en el turn order
        if (!in_array($playerId, $this->turnManager->getTurnOrder())) {
            return;
        }

        if ($permanent) {
            if (!in_array($playerId, $this->permanentlyEliminated)) {
                $this->permanentlyEliminated[] = $playerId;
            }
            // Si estaba en temporales, remover
            $this->temporarilyEliminated = array_values(
                array_filter($this->temporarilyEliminated, fn($id) => $id !== $playerId)
            );
        } else {
            // Eliminación temporal (solo esta ronda)
            if (!in_array($playerId, $this->temporarilyEliminated) &&
                !in_array($playerId, $this->permanentlyEliminated)) {
                $this->temporarilyEliminated[] = $playerId;
            }
        }
    }

    /**
     * Verificar si un jugador está eliminado.
     *
     * @param int $playerId ID del jugador
     * @return bool True si está eliminado (temporal o permanente)
     */
    public function isEliminated(int $playerId): bool
    {
        return in_array($playerId, $this->permanentlyEliminated) ||
               in_array($playerId, $this->temporarilyEliminated);
    }

    /**
     * Verificar si está eliminado permanentemente.
     *
     * @param int $playerId ID del jugador
     * @return bool True si está eliminado permanentemente
     */
    public function isPermanentlyEliminated(int $playerId): bool
    {
        return in_array($playerId, $this->permanentlyEliminated);
    }

    /**
     * Verificar si está eliminado temporalmente.
     *
     * @param int $playerId ID del jugador
     * @return bool True si está eliminado solo esta ronda
     */
    public function isTemporarilyEliminated(int $playerId): bool
    {
        return in_array($playerId, $this->temporarilyEliminated);
    }

    /**
     * Restaurar un jugador eliminado temporalmente.
     *
     * @param int $playerId ID del jugador
     * @return bool True si se restauró, False si era permanente
     */
    public function restorePlayer(int $playerId): bool
    {
        if (in_array($playerId, $this->permanentlyEliminated)) {
            return false; // No se puede restaurar permanentes
        }

        $this->temporarilyEliminated = array_values(
            array_filter($this->temporarilyEliminated, fn($id) => $id !== $playerId)
        );

        return true;
    }

    /**
     * Limpiar todas las eliminaciones temporales.
     *
     * Se llama automáticamente al completar una ronda.
     *
     * @return void
     */
    public function clearTemporaryEliminations(): void
    {
        $this->temporarilyEliminated = [];
    }

    /**
     * Obtener jugadores activos (no eliminados).
     *
     * @return array<int> IDs de jugadores activos
     */
    public function getActivePlayers(): array
    {
        return array_values(
            array_filter(
                $this->turnManager->getTurnOrder(),
                fn($playerId) => !$this->isEliminated($playerId)
            )
        );
    }

    /**
     * Obtener jugadores eliminados permanentemente.
     *
     * @return array<int>
     */
    public function getPermanentlyEliminated(): array
    {
        return $this->permanentlyEliminated;
    }

    /**
     * Obtener jugadores eliminados temporalmente.
     *
     * @return array<int>
     */
    public function getTemporarilyEliminated(): array
    {
        return $this->temporarilyEliminated;
    }

    /**
     * Obtener cantidad de jugadores activos.
     *
     * @return int Número de jugadores no eliminados
     */
    public function getActivePlayerCount(): int
    {
        return count($this->getActivePlayers());
    }

    // ========================================================================
    // ACCESO AL TURNMANAGER
    // ========================================================================

    /**
     * Obtener el TurnManager interno.
     *
     * @return TurnManager
     */
    public function getTurnManager(): TurnManager
    {
        return $this->turnManager;
    }

    /**
     * Obtener el jugador del turno actual.
     *
     * @return mixed ID del jugador en turno
     */
    public function getCurrentPlayer(): mixed
    {
        return $this->turnManager->getCurrentPlayer();
    }

    /**
     * Obtener el orden de turnos.
     *
     * @return array<int>
     */
    public function getTurnOrder(): array
    {
        return $this->turnManager->getTurnOrder();
    }

    /**
     * Verificar si es el turno de un jugador específico.
     *
     * @param int $playerId ID del jugador
     * @return bool
     */
    public function isPlayerTurn(int $playerId): bool
    {
        return $this->turnManager->isPlayerTurn($playerId);
    }

    /**
     * Pausar los turnos.
     *
     * @return void
     */
    public function pause(): void
    {
        $this->turnManager->pause();
    }

    /**
     * Reanudar los turnos.
     *
     * @return void
     */
    public function resume(): void
    {
        $this->turnManager->resume();
    }

    /**
     * Verificar si está pausado.
     *
     * @return bool
     */
    public function isPaused(): bool
    {
        return $this->turnManager->isPaused();
    }

    /**
     * Obtener índice del turno actual.
     *
     * @return int
     */
    public function getCurrentTurnIndex(): int
    {
        return $this->turnManager->getCurrentTurnIndex();
    }

    /**
     * Obtener cantidad de jugadores.
     *
     * @return int
     */
    public function getPlayerCount(): int
    {
        return $this->turnManager->getPlayerCount();
    }

    // ========================================================================
    // SERIALIZACIÓN
    // ========================================================================

    /**
     * Serializar a array para guardar en game_state.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'round_system' => [
                'current_round' => $this->currentRound,
                'total_rounds' => $this->totalRounds,
                'permanently_eliminated' => $this->permanentlyEliminated,
                'temporarily_eliminated' => $this->temporarilyEliminated,
            ],
            // TurnManager mantiene su propio namespace
            'turn_system' => $this->turnManager->toArray(),
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
        // Soporte para ambos formatos: nuevo (round_system) y legacy (claves directas)
        $roundData = $data['round_system'] ?? $data;

        // Restaurar TurnManager primero
        $turnManager = TurnManager::fromArray($data['turn_system'] ?? []);

        $instance = new self(
            turnManager: $turnManager,
            totalRounds: $roundData['total_rounds'] ?? 0,
            currentRound: $roundData['current_round'] ?? 1
        );

        $instance->permanentlyEliminated = $roundData['permanently_eliminated'] ?? [];
        $instance->temporarilyEliminated = $roundData['temporarily_eliminated'] ?? [];

        return $instance;
    }

    /**
     * Programar el avance automático a la siguiente ronda.
     *
     * Útil para juegos simultáneos donde todos juegan al mismo tiempo
     * y después de mostrar resultados se debe avanzar automáticamente.
     *
     * @param callable $callback Función a ejecutar para avanzar la ronda
     * @param int $delaySeconds Segundos de espera antes de ejecutar
     * @return void
     */
    public function scheduleNextRound(callable $callback, int $delaySeconds = 5): void
    {
        dispatch($callback)->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * Verificar si todos los jugadores activos han participado en la ronda actual.
     *
     * Útil para juegos simultáneos donde necesitas saber si todos ya jugaron
     * antes de avanzar.
     *
     * @param array $participatedPlayers IDs de jugadores que ya participaron
     * @return bool True si todos los jugadores activos ya participaron
     */
    public function allPlayersParticipated(array $participatedPlayers): bool
    {
        $activePlayers = $this->getActivePlayers();
        $participated = array_intersect($activePlayers, $participatedPlayers);

        return count($participated) === count($activePlayers);
    }

    /**
     * Determinar si la ronda debe terminar basado en las respuestas en juegos simultáneos.
     *
     * En juegos simultáneos competitivos (como Trivia):
     * - Si alguien acierta → terminar ronda inmediatamente
     * - Si todos fallan → terminar ronda
     * - Si algunos fallan pero no todos respondieron → continuar
     *
     * SOPORTE DE EQUIPOS:
     * - Si NO hay equipos: comportamiento normal (jugadores individuales)
     * - Si hay equipos: depende del modo de equipos
     *
     * @param array $playerResults Array de resultados: [player_id => ['success' => bool]]
     * @return array ['should_end' => bool, 'reason' => string]
     */
    public function shouldEndSimultaneousRound(array $playerResults): array
    {
        // Sin equipos: comportamiento normal
        if (!$this->teamsManager || !$this->teamsManager->isEnabled()) {
            return $this->shouldEndSimultaneousRoundIndividual($playerResults);
        }

        // Con equipos: según el modo
        $mode = $this->teamsManager->getMode();

        return match ($mode) {
            'all_teams' => $this->shouldEndSimultaneousRoundAllTeams($playerResults),
            'team_turns' => $this->shouldEndSimultaneousRoundTeamTurns($playerResults),
            default => $this->shouldEndSimultaneousRoundIndividual($playerResults)
        };
    }

    /**
     * Verificar fin de ronda en modo individual (sin equipos)
     */
    protected function shouldEndSimultaneousRoundIndividual(array $playerResults): array
    {
        $activePlayers = $this->getActivePlayers();
        $totalActivePlayers = count($activePlayers);
        $respondedPlayers = count($playerResults);

        // Verificar si alguien tuvo éxito
        foreach ($playerResults as $result) {
            if ($result['success'] ?? false) {
                return [
                    'should_end' => true,
                    'reason' => 'player_succeeded',
                    'winner_found' => true,
                ];
            }
        }

        // Nadie tuvo éxito aún
        // Verificar si todos ya respondieron
        if ($respondedPlayers >= $totalActivePlayers) {
            return [
                'should_end' => true,
                'reason' => 'all_failed',
                'winner_found' => false,
            ];
        }

        // Aún hay jugadores que no han respondido y nadie acertó
        return [
            'should_end' => false,
            'reason' => 'waiting_for_players',
            'winner_found' => false,
        ];
    }

    /**
     * Verificar fin de ronda en modo "all_teams" (todos los equipos juegan simultáneamente)
     *
     * Ejemplo: Trivia por equipos - todos responden cada pregunta
     * Termina cuando:
     * - Un equipo completo respondió correctamente (todos sus miembros)
     * - O todos los equipos completaron sus respuestas
     */
    protected function shouldEndSimultaneousRoundAllTeams(array $playerResults): array
    {
        $teams = $this->teamsManager->getTeams();

        // Verificar por cada equipo
        foreach ($teams as $team) {
            $teamMembers = $team['members'];
            $teamResponses = array_filter(
                $playerResults,
                fn($playerId) => in_array($playerId, $teamMembers),
                ARRAY_FILTER_USE_KEY
            );

            // ¿Todos los miembros del equipo respondieron?
            if (count($teamResponses) === count($teamMembers)) {
                // ¿Alguno tuvo éxito?
                foreach ($teamResponses as $result) {
                    if ($result['success'] ?? false) {
                        return [
                            'should_end' => true,
                            'reason' => 'team_succeeded',
                            'winner_found' => true,
                            'winning_team_id' => $team['id']
                        ];
                    }
                }
            }
        }

        // Verificar si todos los equipos ya respondieron
        $allTeamsResponded = true;
        foreach ($teams as $team) {
            $teamMembers = $team['members'];
            $teamResponses = array_filter(
                $playerResults,
                fn($playerId) => in_array($playerId, $teamMembers),
                ARRAY_FILTER_USE_KEY
            );

            if (count($teamResponses) < count($teamMembers)) {
                $allTeamsResponded = false;
                break;
            }
        }

        if ($allTeamsResponded) {
            return [
                'should_end' => true,
                'reason' => 'all_teams_failed',
                'winner_found' => false,
            ];
        }

        return [
            'should_end' => false,
            'reason' => 'waiting_for_teams',
            'winner_found' => false,
        ];
    }

    /**
     * Verificar fin de ronda en modo "team_turns" (turnos por equipo)
     *
     * Ejemplo: Pictionary por equipos - un equipo juega su turno completo
     * Termina cuando:
     * - Todos los miembros del equipo actual han respondido
     */
    protected function shouldEndSimultaneousRoundTeamTurns(array $playerResults): array
    {
        $currentTeam = $this->teamsManager->getCurrentTeam();

        if (!$currentTeam) {
            return [
                'should_end' => false,
                'reason' => 'no_current_team',
                'winner_found' => false,
            ];
        }

        $teamMembers = $currentTeam['members'];
        $teamResponses = array_filter(
            $playerResults,
            fn($playerId) => in_array($playerId, $teamMembers),
            ARRAY_FILTER_USE_KEY
        );

        // Verificar si alguien del equipo tuvo éxito
        foreach ($teamResponses as $result) {
            if ($result['success'] ?? false) {
                return [
                    'should_end' => true,
                    'reason' => 'team_member_succeeded',
                    'winner_found' => true,
                    'team_id' => $currentTeam['id']
                ];
            }
        }

        // Verificar si todos los miembros del equipo ya respondieron
        if (count($teamResponses) >= count($teamMembers)) {
            return [
                'should_end' => true,
                'reason' => 'team_all_failed',
                'winner_found' => false,
                'team_id' => $currentTeam['id']
            ];
        }

        return [
            'should_end' => false,
            'reason' => 'waiting_for_team_members',
            'winner_found' => false,
        ];
    }

    // ========================================================================
    // INTEGRACIÓN CON EQUIPOS
    // ========================================================================

    /**
     * Establecer el TeamsManager para juegos por equipos
     */
    public function setTeamsManager(?TeamsManager $teamsManager): void
    {
        $this->teamsManager = $teamsManager;
    }

    /**
     * Obtener el TeamsManager
     */
    public function getTeamsManager(): ?TeamsManager
    {
        return $this->teamsManager;
    }

    /**
     * Verificar si el juego se está jugando por equipos
     */
    public function isTeamsMode(): bool
    {
        return $this->teamsManager && $this->teamsManager->isEnabled();
    }
}

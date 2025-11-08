<?php

namespace Games\Uno;

use App\Contracts\BaseGameEngine;
use App\Events\Uno\CardPlayedEvent;
use App\Events\Uno\CardDrawnEvent;
use App\Events\Uno\TurnChangedEvent;
use App\Events\Uno\UnoCalledEvent;
use App\Events\Uno\PlayerWonEvent;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Modules\RoundSystem\RoundManager;
use Illuminate\Support\Facades\Log;

/**
 * UNO Game Engine
 *
 * Implementa el clásico juego de cartas UNO con todas sus reglas:
 * - Turnos secuenciales con posibilidad de reversa
 * - Cartas especiales: Skip, Reverse, +2, Wild, Wild +4
 * - Sistema de "decir UNO"
 * - Penalizaciones por no decir UNO
 * - Acumulación de cartas +2 y +4 (opcional)
 */
class UnoEngine extends BaseGameEngine
{
    /**
     * Score calculator instance
     */
    protected UnoScoreCalculator $scoreCalculator;

    public function __construct()
    {
        // Cargar configuración del juego
        $gameConfig = $this->getGameConfig();
        $scoringConfig = $gameConfig['scoring'] ?? [];

        // Inicializar calculator con la configuración
        $this->scoreCalculator = new UnoScoreCalculator($scoringConfig);
    }

    /**
     * Inicializar el juego - FASE 1 (LOBBY)
     *
     * Crea el mazo de cartas y prepara el juego.
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("[UNO] Initializing - FASE 1", ['match_id' => $match->id]);

        // Cargar configuración del juego
        $gameConfig = $this->getGameConfig();
        $gameSettings = $match->room->game_settings ?? [];

        // Obtener configuraciones personalizadas
        $totalRounds = $gameSettings['rounds'] ?? $gameConfig['customizableSettings']['rounds']['default'];
        $startingCards = $gameSettings['starting_cards'] ?? $gameConfig['customizableSettings']['starting_cards']['default'];
        $unoPenalty = $gameSettings['uno_penalty'] ?? $gameConfig['customizableSettings']['uno_penalty']['default'];
        $allowStacking = $gameSettings['allow_stacking'] ?? $gameConfig['customizableSettings']['allow_stacking']['default'];

        // Crear mapeo user_id => player_id
        $userToPlayerMap = [];
        foreach ($match->players as $player) {
            $userToPlayerMap[$player->user_id] = $player->id;
        }

        // Guardar configuración inicial
        $match->game_state = [
            '_config' => [
                'game' => 'uno',
                'initialized_at' => now()->toDateTimeString(),
                'user_to_player_map' => $userToPlayerMap,
                'timing' => $gameConfig['timing'] ?? null,
                'modules' => $gameConfig['modules'] ?? [],
                'starting_cards' => $startingCards,
                'uno_penalty' => $unoPenalty,
                'allow_stacking' => $allowStacking,
            ],
            'phase' => 'waiting',
            'deck' => [],
            'discard_pile' => [],
            'player_hands' => [],
            'current_card' => null,
            'current_color' => null,
            'direction' => 1, // 1 = horario, -1 = antihorario
            'uno_declared' => [], // player_id => bool
            'pending_draw' => 0, // Para acumular +2 y +4
        ];

        $match->save();

        // Inicializar módulos automáticamente desde config.json
        $this->initializeModules($match, [
            'scoring_system' => [
                'calculator' => $this->scoreCalculator
            ],
            'round_system' => [
                'total_rounds' => $totalRounds
            ]
        ]);

        // Inicializar PlayerManager
        $playerIds = $match->players->pluck('id')->toArray();
        $availableRoles = ['player'];

        $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager(
            $playerIds,
            $this->scoreCalculator,
            [
                'available_roles' => $availableRoles,
                'allow_multiple_persistent_roles' => false,
                'track_score_history' => false,
            ]
        );
        $this->savePlayerManager($match, $playerManager);

        Log::info("[UNO] Initialized successfully", [
            'match_id' => $match->id,
            'total_players' => count($playerIds),
            'total_rounds' => $totalRounds,
            'starting_cards' => $startingCards
        ]);
    }

    /**
     * Hook cuando el juego empieza.
     */
    protected function onGameStart(GameMatch $match): void
    {
        Log::info('[UNO] onGameStart', ['match_id' => $match->id]);

        $match->game_state = array_merge($match->game_state, [
            'phase' => 'playing',
            'started_at' => now()->toDateTimeString(),
        ]);
        $match->save();

        // Iniciar primera ronda
        $this->handleNewRound($match, advanceRound: false);
    }

    /**
     * Hook antes de iniciar una nueva ronda
     */
    protected function onRoundStarting(GameMatch $match): void
    {
        Log::info('[UNO] onRoundStarting', ['match_id' => $match->id]);

        // Crear y barajar el mazo
        $deck = $this->createDeck();
        $deck = $this->shuffleDeck($deck);

        // Repartir cartas iniciales a cada jugador
        $playerIds = $match->players->pluck('id')->toArray();
        $startingCards = $match->game_state['_config']['starting_cards'];
        $playerHands = [];
        $unoDeclared = [];

        foreach ($playerIds as $playerId) {
            $playerHands[$playerId] = [];
            $unoDeclared[$playerId] = false;

            for ($i = 0; $i < $startingCards; $i++) {
                $playerHands[$playerId][] = array_pop($deck);
            }
        }

        // Colocar primera carta en la pila de descarte
        // Asegurarse de que no sea una carta Wild
        do {
            $firstCard = array_pop($deck);
        } while ($firstCard['type'] === 'wild');

        $discardPile = [$firstCard];
        $currentColor = $firstCard['color'] ?? null;

        // Actualizar estado del juego
        $match->game_state = array_merge($match->game_state, [
            'deck' => $deck,
            'discard_pile' => $discardPile,
            'player_hands' => $playerHands,
            'current_card' => $firstCard,
            'current_color' => $currentColor,
            'direction' => 1,
            'uno_declared' => $unoDeclared,
            'pending_draw' => 0,
        ]);
        $match->save();

        // Aplicar efecto de la primera carta si es especial
        $this->applyCardEffect($match, $firstCard, null, true);

        Log::info('[UNO] Round started', [
            'match_id' => $match->id,
            'first_card' => $firstCard,
            'hands_dealt' => array_map('count', $playerHands)
        ]);
    }

    /**
     * Procesar acción de ronda.
     *
     * @param GameMatch $match
     * @param Player $player
     * @param array $data ['action' => 'play_card|draw_card|declare_uno|challenge_uno', ...]
     * @return array ['success' => bool, 'force_end' => bool, ...]
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        $action = $data['action'] ?? null;

        return match ($action) {
            'play_card' => $this->handlePlayCard($match, $player, $data),
            'draw_card' => $this->handleDrawCard($match, $player, $data),
            'declare_uno' => $this->handleDeclareUno($match, $player, $data),
            'challenge_uno' => $this->handleChallengeUno($match, $player, $data),
            default => [
                'success' => false,
                'error' => 'Acción desconocida',
                'force_end' => false,
            ],
        };
    }

    /**
     * Handle: Jugador juega una carta
     */
    protected function handlePlayCard(GameMatch $match, Player $player, array $data): array
    {
        $gameState = $match->game_state;
        $cardIndex = $data['card_index'] ?? null;
        $chosenColor = $data['chosen_color'] ?? null; // Para cartas Wild

        // Validar turno
        $roundManager = $this->getRoundManager($match);
        if (!$roundManager->isPlayerTurn($player->id)) {
            return [
                'success' => false,
                'error' => 'No es tu turno',
                'force_end' => false,
            ];
        }

        // Validar índice de carta
        $playerHand = $gameState['player_hands'][$player->id] ?? [];
        if ($cardIndex === null || $cardIndex < 0 || $cardIndex >= count($playerHand)) {
            return [
                'success' => false,
                'error' => 'Carta inválida',
                'force_end' => false,
            ];
        }

        $card = $playerHand[$cardIndex];

        // Validar jugada
        if (!$this->isValidPlay($card, $gameState)) {
            return [
                'success' => false,
                'error' => 'No puedes jugar esa carta',
                'force_end' => false,
            ];
        }

        // Remover carta de la mano del jugador
        array_splice($playerHand, $cardIndex, 1);
        $gameState['player_hands'][$player->id] = $playerHand;

        // Agregar a pila de descarte
        $gameState['discard_pile'][] = $card;
        $gameState['current_card'] = $card;

        // Si es Wild, establecer color elegido
        if ($card['type'] === 'wild') {
            if (!$chosenColor || !in_array($chosenColor, ['red', 'blue', 'green', 'yellow'])) {
                return [
                    'success' => false,
                    'error' => 'Debes elegir un color',
                    'force_end' => false,
                ];
            }
            $gameState['current_color'] = $chosenColor;
        } else {
            $gameState['current_color'] = $card['color'];
        }

        $match->game_state = $gameState;
        $match->save();

        // Verificar si el jugador ganó
        if (count($playerHand) === 0) {
            return $this->handlePlayerWin($match, $player);
        }

        // Si le queda 1 carta y no declaró UNO, penalizar
        if (count($playerHand) === 1 && !$gameState['uno_declared'][$player->id]) {
            $this->applyUnoPenalty($match, $player);
        }

        // Emitir evento de carta jugada
        broadcast(new CardPlayedEvent($match, $player, $card, $chosenColor))->toOthers();

        // Aplicar efecto de la carta
        $this->applyCardEffect($match, $card, $player);

        // Avanzar turno (si la carta no era Skip)
        if ($card['value'] !== 'skip') {
            $this->nextTurn($match);
        } else {
            // Skip: avanzar 2 turnos (salta el siguiente jugador)
            $this->nextTurn($match);
            $this->nextTurn($match);
        }

        return [
            'success' => true,
            'message' => 'Carta jugada correctamente',
            'force_end' => false,
            'card_played' => $card,
            'cards_remaining' => count($playerHand),
        ];
    }

    /**
     * Handle: Jugador roba una carta
     */
    protected function handleDrawCard(GameMatch $match, Player $player, array $data): array
    {
        $gameState = $match->game_state;

        // Validar turno
        $roundManager = $this->getRoundManager($match);
        if (!$roundManager->isPlayerTurn($player->id)) {
            return [
                'success' => false,
                'error' => 'No es tu turno',
                'force_end' => false,
            ];
        }

        // Determinar cuántas cartas robar
        $cardsToDraw = max(1, $gameState['pending_draw']);
        $drawnCards = [];

        for ($i = 0; $i < $cardsToDraw; $i++) {
            $card = $this->drawCardFromDeck($match);
            if ($card) {
                $gameState['player_hands'][$player->id][] = $card;
                $drawnCards[] = $card;
            }
        }

        // Resetear pending_draw
        $gameState['pending_draw'] = 0;
        $match->game_state = $gameState;
        $match->save();

        // Emitir evento
        broadcast(new CardDrawnEvent($match, $player, count($drawnCards)))->toOthers();

        // Avanzar turno
        $this->nextTurn($match);

        return [
            'success' => true,
            'message' => "Robaste {$cardsToDraw} carta(s)",
            'force_end' => false,
            'cards_drawn' => $drawnCards,
        ];
    }

    /**
     * Handle: Jugador declara UNO
     */
    protected function handleDeclareUno(GameMatch $match, Player $player, array $data): array
    {
        $gameState = $match->game_state;
        $playerHand = $gameState['player_hands'][$player->id] ?? [];

        // Solo se puede declarar UNO si tienes exactamente 1 carta
        if (count($playerHand) !== 1) {
            return [
                'success' => false,
                'error' => 'Solo puedes declarar UNO con 1 carta',
                'force_end' => false,
            ];
        }

        $gameState['uno_declared'][$player->id] = true;
        $match->game_state = $gameState;
        $match->save();

        // Emitir evento
        broadcast(new UnoCalledEvent($match, $player))->toOthers();

        return [
            'success' => true,
            'message' => '¡UNO!',
            'force_end' => false,
        ];
    }

    /**
     * Handle: Jugador desafía a otro por no decir UNO
     */
    protected function handleChallengeUno(GameMatch $match, Player $challenger, array $data): array
    {
        $gameState = $match->game_state;
        $targetPlayerId = $data['target_player_id'] ?? null;

        if (!$targetPlayerId) {
            return [
                'success' => false,
                'error' => 'Debes especificar a quién desafías',
                'force_end' => false,
            ];
        }

        $targetHand = $gameState['player_hands'][$targetPlayerId] ?? [];
        $targetDeclaredUno = $gameState['uno_declared'][$targetPlayerId] ?? false;

        // Verificar si el jugador objetivo tiene 1 carta y NO declaró UNO
        if (count($targetHand) === 1 && !$targetDeclaredUno) {
            // Desafío exitoso: el jugador objetivo roba cartas
            $this->applyUnoPenalty($match, Player::find($targetPlayerId));

            return [
                'success' => true,
                'message' => '¡Desafío exitoso! El jugador debe robar cartas',
                'force_end' => false,
            ];
        }

        return [
            'success' => false,
            'error' => 'Desafío fallido',
            'force_end' => false,
        ];
    }

    /**
     * Validar si una carta se puede jugar
     */
    protected function isValidPlay(array $card, array $gameState): bool
    {
        $currentCard = $gameState['current_card'];
        $currentColor = $gameState['current_color'];
        $pendingDraw = $gameState['pending_draw'];

        // Si hay pending_draw, solo se puede jugar +2 o +4 (si está permitido acumular)
        if ($pendingDraw > 0) {
            if ($gameState['_config']['allow_stacking']) {
                return in_array($card['value'], ['draw2', 'wild_draw4']);
            } else {
                // No se puede jugar nada, debe robar
                return false;
            }
        }

        // Wild siempre se puede jugar
        if ($card['type'] === 'wild') {
            return true;
        }

        // Mismo color o mismo número/valor
        if ($card['color'] === $currentColor) {
            return true;
        }

        if ($card['value'] === $currentCard['value']) {
            return true;
        }

        return false;
    }

    /**
     * Aplicar efecto de una carta especial
     */
    protected function applyCardEffect(GameMatch $match, array $card, ?Player $player, bool $isFirstCard = false): void
    {
        $gameState = $match->game_state;

        switch ($card['value']) {
            case 'reverse':
                // Invertir dirección
                $gameState['direction'] *= -1;
                $match->game_state = $gameState;
                $match->save();

                // Actualizar dirección en el RoundManager
                $roundManager = $this->getRoundManager($match);
                $roundManager->setDirection($gameState['direction']);
                $this->saveRoundManager($match, $roundManager);
                break;

            case 'skip':
                // El efecto de skip se maneja en handlePlayCard
                break;

            case 'draw2':
                // Acumular +2
                $gameState['pending_draw'] += 2;
                $match->game_state = $gameState;
                $match->save();
                break;

            case 'wild_draw4':
                // Acumular +4
                $gameState['pending_draw'] += 4;
                $match->game_state = $gameState;
                $match->save();
                break;
        }
    }

    /**
     * Aplicar penalización por no decir UNO
     */
    protected function applyUnoPenalty(GameMatch $match, Player $player): void
    {
        $gameState = $match->game_state;
        $unoPenalty = $gameState['_config']['uno_penalty'];

        Log::info('[UNO] Applying UNO penalty', [
            'player_id' => $player->id,
            'penalty' => $unoPenalty
        ]);

        for ($i = 0; $i < $unoPenalty; $i++) {
            $card = $this->drawCardFromDeck($match);
            if ($card) {
                $gameState['player_hands'][$player->id][] = $card;
            }
        }

        $match->game_state = $gameState;
        $match->save();
    }

    /**
     * Manejar victoria de un jugador
     */
    protected function handlePlayerWin(GameMatch $match, Player $winner): array
    {
        Log::info('[UNO] Player won the round', [
            'match_id' => $match->id,
            'player_id' => $winner->id
        ]);

        $gameState = $match->game_state;

        // Calcular puntos (suma de cartas de los oponentes)
        $opponentHands = [];
        foreach ($gameState['player_hands'] as $playerId => $hand) {
            if ($playerId != $winner->id) {
                $opponentHands[$playerId] = $hand;
            }
        }

        // Agregar puntos al ganador
        $playerManager = $this->getPlayerManager($match);
        $scoreResult = $playerManager->addScore('round_won', [
            'player_id' => $winner->id,
            'opponent_hands' => $opponentHands
        ]);
        $this->savePlayerManager($match, $playerManager);

        // Emitir evento
        broadcast(new PlayerWonEvent($match, $winner, $scoreResult['points']))->toOthers();

        // Terminar la ronda
        return [
            'success' => true,
            'message' => '¡Ganaste la ronda!',
            'force_end' => true,
            'winner_id' => $winner->id,
            'points_earned' => $scoreResult['points']
        ];
    }

    /**
     * Avanzar al siguiente turno
     */
    protected function nextTurn(GameMatch $match): void
    {
        $roundManager = $this->getRoundManager($match);
        $roundManager->nextTurn();
        $this->saveRoundManager($match, $roundManager);

        $currentPlayerId = $roundManager->getCurrentPlayer();

        // Emitir evento de cambio de turno
        broadcast(new TurnChangedEvent($match, $currentPlayerId))->toOthers();

        Log::info('[UNO] Turn changed', [
            'match_id' => $match->id,
            'current_player' => $currentPlayerId
        ]);
    }

    /**
     * Robar una carta del mazo
     */
    protected function drawCardFromDeck(GameMatch $match): ?array
    {
        $gameState = $match->game_state;
        $deck = $gameState['deck'];

        // Si el mazo está vacío, rebarajar la pila de descarte
        if (empty($deck)) {
            $discardPile = $gameState['discard_pile'];
            $currentCard = array_pop($discardPile); // Mantener la última carta

            $deck = $this->shuffleDeck($discardPile);
            $gameState['deck'] = $deck;
            $gameState['discard_pile'] = [$currentCard];
            $match->game_state = $gameState;
            $match->save();
        }

        if (empty($deck)) {
            return null;
        }

        $card = array_pop($deck);
        $gameState['deck'] = $deck;
        $match->game_state = $gameState;
        $match->save();

        return $card;
    }

    /**
     * Crear un mazo completo de UNO
     */
    protected function createDeck(): array
    {
        $deck = [];
        $gameConfig = $this->getGameConfig();
        $colors = $gameConfig['cards']['colors'];
        $numbers = $gameConfig['cards']['numbers'];
        $specialCards = $gameConfig['cards']['special_cards'];
        $wildCards = $gameConfig['cards']['wild_cards'];

        // Cartas numéricas (0: 1 por color, 1-9: 2 por color)
        foreach ($colors as $color) {
            foreach ($numbers as $number) {
                $count = ($number === 0) ? 1 : 2;
                for ($i = 0; $i < $count; $i++) {
                    $deck[] = [
                        'type' => 'number',
                        'value' => $number,
                        'color' => $color
                    ];
                }
            }
        }

        // Cartas especiales (2 por color)
        foreach ($colors as $color) {
            foreach ($specialCards as $special) {
                for ($i = 0; $i < 2; $i++) {
                    $deck[] = [
                        'type' => 'special',
                        'value' => $special,
                        'color' => $color
                    ];
                }
            }
        }

        // Cartas Wild (4 de cada tipo)
        foreach ($wildCards as $wild) {
            for ($i = 0; $i < 4; $i++) {
                $deck[] = [
                    'type' => 'wild',
                    'value' => $wild,
                    'color' => null
                ];
            }
        }

        return $deck;
    }

    /**
     * Barajar el mazo
     */
    protected function shuffleDeck(array $deck): array
    {
        shuffle($deck);
        return $deck;
    }

    /**
     * Iniciar nueva ronda
     */
    protected function startNewRound(GameMatch $match): void
    {
        Log::info("[UNO] Starting new round", ['match_id' => $match->id]);
        // La lógica está en onRoundStarting
    }

    /**
     * Finalizar ronda actual
     */
    public function endCurrentRound(GameMatch $match): void
    {
        Log::info("[UNO] Ending current round", ['match_id' => $match->id]);
        // Limpiar estado para la siguiente ronda
        $gameState = $match->game_state;
        $gameState['uno_declared'] = array_fill_keys(array_keys($gameState['uno_declared']), false);
        $match->game_state = $gameState;
        $match->save();
    }

    /**
     * Obtener resultados de todos los jugadores
     */
    protected function getAllPlayerResults(GameMatch $match): array
    {
        $playerManager = $this->getPlayerManager($match);
        $ranking = $playerManager->getRanking();

        $results = [];
        foreach ($ranking as $rank) {
            $results[] = [
                'player_id' => $rank['player_id'],
                'score' => $rank['score'],
                'rank' => $rank['rank']
            ];
        }

        return $results;
    }
}

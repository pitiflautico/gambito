<?php

namespace App\Services\Core;

use App\Models\Game;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Servicio para gestionar salas de juego.
 *
 * Responsabilidades:
 * - Generar códigos únicos de sala (6 caracteres alfanuméricos)
 * - Crear URLs de invitación
 * - Generar códigos QR para compartir
 * - Validar códigos de sala
 * - Gestionar estado de salas
 */
class RoomService
{
    /**
     * Longitud del código de sala.
     */
    protected const CODE_LENGTH = 6;

    /**
     * Número máximo de intentos para generar un código único.
     */
    protected const MAX_ATTEMPTS = 10;

    /**
     * Crear una nueva sala de juego.
     *
     * @param Game $game El juego para el que se crea la sala
     * @param User $master El usuario que crea la sala
     * @param array $settings Configuración personalizada de la sala
     * @return Room La sala creada
     * @throws \RuntimeException Si no se puede generar un código único
     */
    public function createRoom(Game $game, User $master, array $settings = []): Room
    {
        // Validar que el juego esté activo
        if (!$game->is_active) {
            throw new \InvalidArgumentException("Cannot create room for inactive game: {$game->slug}");
        }

        // Validar configuración de jugadores si se especifica
        if (isset($settings['max_players'])) {
            if (!$game->isValidPlayerCount($settings['max_players'])) {
                throw new \InvalidArgumentException(
                    "Invalid player count {$settings['max_players']}. Game allows {$game->min_players}-{$game->max_players} players."
                );
            }
        }

        // Generar código único
        $code = $this->generateUniqueCode();

        // Separar game_settings de settings generales
        $gameSettings = [];
        if (isset($settings['play_with_teams'])) {
            $gameSettings['play_with_teams'] = $settings['play_with_teams'];
            unset($settings['play_with_teams']);
        }

        // Crear sala
        $room = Room::create([
            'code' => $code,
            'game_id' => $game->id,
            'master_id' => $master->id,
            'status' => Room::STATUS_WAITING,
            'settings' => $settings,
            'game_settings' => !empty($gameSettings) ? $gameSettings : null,
        ]);

        Log::info("Room created", [
            'room_id' => $room->id,
            'code' => $code,
            'game' => $game->slug,
            'master' => $master->email,
        ]);

        return $room;
    }

    /**
     * Generar un código único de sala.
     *
     * Genera códigos alfanuméricos de 6 caracteres (ej: "ABC123")
     * excluyendo caracteres confusos (0, O, I, 1).
     *
     * @return string Código único generado
     * @throws \RuntimeException Si no se puede generar un código único después de MAX_ATTEMPTS intentos
     */
    public function generateUniqueCode(): string
    {
        $attempts = 0;

        do {
            $code = $this->generateCode();
            $attempts++;

            if ($attempts >= self::MAX_ATTEMPTS) {
                throw new \RuntimeException("Failed to generate unique room code after {$attempts} attempts");
            }
        } while (Room::where('code', $code)->exists());

        return $code;
    }

    /**
     * Generar un código aleatorio.
     *
     * Usa caracteres alfanuméricos excluyendo los confusos (0, O, I, 1).
     *
     * @return string Código generado
     */
    protected function generateCode(): string
    {
        // Caracteres permitidos (sin 0, O, I, 1 para evitar confusión)
        $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $characters[rand(0, strlen($characters) - 1)];
        }

        return $code;
    }

    /**
     * Validar un código de sala.
     *
     * @param string $code Código a validar
     * @return bool True si el código tiene el formato correcto
     */
    public function isValidCodeFormat(string $code): bool
    {
        // Debe tener exactamente CODE_LENGTH caracteres alfanuméricos
        return preg_match('/^[A-Z0-9]{' . self::CODE_LENGTH . '}$/', strtoupper($code)) === 1;
    }

    /**
     * Buscar una sala por su código.
     *
     * @param string $code Código de la sala
     * @return Room|null La sala encontrada o null
     */
    public function findRoomByCode(string $code): ?Room
    {
        return Room::where('code', strtoupper($code))->first();
    }

    /**
     * Generar URL de invitación para una sala.
     *
     * @param Room $room La sala
     * @return string URL completa para unirse a la sala
     */
    public function getInviteUrl(Room $room): string
    {
        return route('rooms.lobby', ['code' => $room->code]);
    }

    /**
     * Generar URL del código QR para una sala.
     *
     * Usa el servicio de QuickChart.io para generar QR codes sin dependencias.
     *
     * @param Room $room La sala
     * @param int $size Tamaño del QR en píxeles (default: 300)
     * @return string URL de la imagen QR
     */
    public function getQrCodeUrl(Room $room, int $size = 300): string
    {
        $inviteUrl = $this->getInviteUrl($room);

        // Usar QuickChart.io para generar QR sin dependencias
        return "https://quickchart.io/qr?text=" . urlencode($inviteUrl) . "&size={$size}";
    }

    /**
     * Verificar si una sala puede iniciar la partida.
     *
     * @param Room $room La sala
     * @return array ['can_start' => bool, 'reason' => string|null]
     */
    public function canStartGame(Room $room): array
    {
        // Verificar estado de la sala
        if ($room->status !== Room::STATUS_WAITING) {
            return [
                'can_start' => false,
                'reason' => 'La sala no está en estado de espera',
            ];
        }

        // Verificar que haya una partida asociada
        if (!$room->match) {
            return [
                'can_start' => false,
                'reason' => 'No hay partida asociada a la sala',
            ];
        }

        // Contar jugadores conectados
        $playerCount = $room->match->players()->where('is_connected', true)->count();

        // Verificar número mínimo de jugadores
        $minPlayers = $room->game->min_players;
        if ($playerCount < $minPlayers) {
            return [
                'can_start' => false,
                'reason' => "Se necesitan al menos {$minPlayers} jugadores. Actualmente hay {$playerCount}.",
            ];
        }

        // Verificar número máximo de jugadores (si está configurado en settings)
        if (isset($room->settings['max_players'])) {
            $maxPlayers = $room->settings['max_players'];
            if ($playerCount > $maxPlayers) {
                return [
                    'can_start' => false,
                    'reason' => "Demasiados jugadores. Máximo permitido: {$maxPlayers}.",
                ];
            }
        }

        // Verificar configuración de equipos si está activado
        if (isset($room->game_settings['play_with_teams']) && $room->game_settings['play_with_teams']) {
            // Obtener estado de equipos desde Redis/BD
            try {
                $teamsManager = new \App\Services\Modules\TeamsSystem\TeamsManager($room->match);

                if (!$teamsManager->isEnabled()) {
                    return [
                        'can_start' => false,
                        'reason' => 'Los equipos no han sido inicializados. El master debe crear los equipos primero.',
                    ];
                }

                // Verificar que todos los jugadores estén asignados a un equipo
                $teams = $teamsManager->getTeams();
                $playersInTeams = [];
                foreach ($teams as $team) {
                    $playersInTeams = array_merge($playersInTeams, $team['members']);
                }

                $allPlayerIds = $room->match->players()->where('is_connected', true)->pluck('id')->toArray();
                $unassignedPlayers = array_diff($allPlayerIds, $playersInTeams);

                if (!empty($unassignedPlayers)) {
                    $count = count($unassignedPlayers);
                    return [
                        'can_start' => false,
                        'reason' => "Hay {$count} jugador(es) sin equipo asignado. Todos deben estar en un equipo.",
                    ];
                }

                // Verificar que cada equipo tenga al menos 1 jugador
                foreach ($teams as $team) {
                    if (empty($team['members'])) {
                        return [
                            'can_start' => false,
                            'reason' => "El equipo '{$team['name']}' no tiene jugadores. Elimínalo o asigna jugadores.",
                        ];
                    }
                }
            } catch (\Exception $e) {
                return [
                    'can_start' => false,
                    'reason' => 'Error al verificar equipos: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'can_start' => true,
            'reason' => null,
        ];
    }

    /**
     * Iniciar la partida de una sala.
     *
     * @param Room $room La sala
     * @return bool True si se inició correctamente
     * @throws \RuntimeException Si no se puede iniciar la partida
     */
    public function startGame(Room $room): bool
    {
        $validation = $this->canStartGame($room);

        if (!$validation['can_start']) {
            throw new \RuntimeException($validation['reason']);
        }

        // Cambiar estado de la sala
        $room->update(['status' => Room::STATUS_PLAYING]);

        // Iniciar la partida (esto emitirá GameStartedEvent)
        $room->match->start();

        Log::info("Game started", [
            'room_id' => $room->id,
            'code' => $room->code,
            'game' => $room->game->slug,
            'players' => $room->match->players()->count(),
        ]);

        return true;
    }

    /**
     * Finalizar la partida de una sala.
     *
     * @param Room $room La sala
     * @param int|null $winnerId ID del jugador ganador (opcional)
     * @return bool True si se finalizó correctamente
     */
    public function finishGame(Room $room, ?int $winnerId = null): bool
    {
        // Verificar que la sala esté jugando
        if ($room->status !== Room::STATUS_PLAYING) {
            throw new \RuntimeException("Cannot finish game: room is not in playing status");
        }

        // Finalizar la partida
        $room->match->finish($winnerId);

        // Cambiar estado de la sala
        $room->update(['status' => Room::STATUS_FINISHED]);

        Log::info("Game finished", [
            'room_id' => $room->id,
            'code' => $room->code,
            'game' => $room->game->slug,
            'winner_id' => $winnerId,
        ]);

        return true;
    }

    /**
     * Verificar si el master (creador) de la sala está conectado.
     *
     * @param Room $room La sala
     * @return bool True si el master está conectado
     */
    public function isMasterConnected(Room $room): bool
    {
        if (!$room->match) {
            return false;
        }

        $masterPlayer = $room->match->players()
            ->where('user_id', $room->master_id)
            ->first();

        if (!$masterPlayer) {
            return false;
        }

        return $masterPlayer->is_connected;
    }

    /**
     * Cerrar una sala (eliminar o marcar como inactiva).
     *
     * @param Room $room La sala
     * @return bool True si se cerró correctamente
     */
    public function closeRoom(Room $room): bool
    {
        // Si la sala está jugando, finalizarla primero
        if ($room->status === Room::STATUS_PLAYING) {
            $this->finishGame($room);
        }

        // Marcar como finished si aún no lo está
        if ($room->status !== Room::STATUS_FINISHED) {
            $room->update(['status' => Room::STATUS_FINISHED]);
        }

        Log::info("Room closed", [
            'room_id' => $room->id,
            'code' => $room->code,
        ]);

        return true;
    }

    /**
     * Obtener estadísticas de una sala.
     *
     * @param Room $room La sala
     * @return array Estadísticas de la sala
     */
    public function getRoomStats(Room $room): array
    {
        $match = $room->match;

        if (!$match) {
            return [
                'status' => $room->status,
                'players' => 0,
                'connected' => 0,
                'duration' => null,
            ];
        }

        return [
            'status' => $room->status,
            'players' => $match->players()->count(),
            'connected' => $match->players()->where('is_connected', true)->count(),
            'duration' => $match->duration(),
            'started_at' => $match->started_at?->toIso8601String(),
            'finished_at' => $match->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Limpiar salas antiguas (finished hace más de X horas).
     *
     * @param int $hoursOld Número de horas para considerar una sala antigua
     * @return int Número de salas eliminadas
     */
    public function cleanupOldRooms(int $hoursOld = 24): int
    {
        $cutoffTime = now()->subHours($hoursOld);

        $count = Room::where('status', Room::STATUS_FINISHED)
            ->where('updated_at', '<', $cutoffTime)
            ->delete();

        Log::info("Cleaned up old rooms", [
            'count' => $count,
            'hours_old' => $hoursOld,
        ]);

        return $count;
    }
}

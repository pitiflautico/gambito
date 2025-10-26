<?php

use App\Models\Room;
use App\Models\Player;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Aquí se registran los canales de broadcasting para WebSockets.
| Cada canal debe retornar true si el usuario tiene autorización.
|
*/

// Canal de usuario (autenticado)
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Presence Channel - Trackea automáticamente quién está conectado
// NOTA: Laravel añade automáticamente el prefijo "presence-" cuando usas Echo.join()
// Por eso aquí solo ponemos "room.{code}" aunque en JS se usa join('room.{code}')
Broadcast::channel('room.{code}', function ($user, string $code) {
    // IMPORTANTE: Todos los usuarios (incluidos invitados) están autenticados
    // Los invitados tienen rol 'guest' y se autentican automáticamente al unirse

    if (!$user) {
        \Log::warning('❌ Presence Channel: No authenticated user', [
            'room_code' => $code,
        ]);
        return false;
    }

    // Verificar que la sala existe
    $room = Room::where('code', $code)->first();

    if (!$room) {
        \Log::warning('❌ Presence Channel: Room not found', [
            'room_code' => $code,
            'user_id' => $user->id,
        ]);
        return false;
    }

    $match = $room->match;
    if (!$match) {
        \Log::warning('❌ Presence Channel: Match not found', [
            'room_code' => $code,
            'user_id' => $user->id,
        ]);
        return false;
    }

    // Buscar el jugador por session_id o user_id
    $sessionId = request()->session()->getId();

    $player = null;

    // Buscar por user_id (funciona tanto para usuarios normales como invitados)
    $player = Player::where('match_id', $match->id)
        ->where('user_id', $user->id)
        ->first();

    // Si no se encontró por user_id, buscar por session_id (fallback para compatibility)
    if (!$player && $sessionId) {
        $player = Player::where('match_id', $match->id)
            ->where('session_id', $sessionId)
            ->first();
    }

    if (!$player) {
        \Log::warning('❌ Presence Channel: Player not found', [
            'room_code' => $code,
            'user_id' => $user->id,
            'user_role' => $user->role,
            'session_id' => substr($sessionId, 0, 20) . '...',
        ]);
        return false;
    }

    \Log::info('✅ Presence Channel: Authorized', [
        'player_id' => $player->id,
        'player_name' => $player->name,
        'user_id' => $user->id,
        'user_role' => $user->role,
        'room_code' => $code,
    ]);

    // Retornar info del jugador para Presence Channel
    return [
        'id' => $player->id,
        'name' => $player->name,
        'role' => $player->role,
    ];
});

/*
|--------------------------------------------------------------------------
| WebSocket Bidirectional Communication (Fase 3-4)
|--------------------------------------------------------------------------
|
| NOTA: Laravel Broadcasting/Reverb no soporta "whispers handlers" nativamente.
| Los whispers son eventos peer-to-peer (cliente-a-cliente) que no pasan por
| el servidor de aplicación.
|
| Para comunicación bidireccional (cliente → servidor → cliente), usamos:
|
| FASE 3 (Backend - COMPLETADO):
| ✅ HandleClientGameAction listener creado
| ✅ PlayerActionEvent para respuestas
| ✅ Procesamiento de acciones desacoplado del controlador
|
| FASE 4 (Frontend - PENDIENTE):
| - Opción A: Mantener HTTP POST pero optimizado (rápido, simple)
| - Opción B: Usar eventos personalizados via WebSocket
| - Opción C: Implementar custom protocol con Laravel Reverb
|
| El listener app/Listeners/HandleClientGameAction está preparado para:
| 1. Recibir eventos desde HTTP (actual)
| 2. Recibir eventos desde WebSocket (futuro)
| 3. Procesar acciones de forma unificada
| 4. Emitir PlayerActionEvent con resultados
|
| Uso actual (HTTP):
| PlayController::apiProcessAction() → HandleClientGameAction::handle()
|
| Uso futuro (WebSocket - a implementar en Fase 4):
| Frontend: channel.whisper('game.action', {action, data})
| Backend: Middleware/Handler → HandleClientGameAction::handle()
| Backend: emit PlayerActionEvent
| Frontend: channel.listen('.game.player.action', handler)
|
*/

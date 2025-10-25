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
    // Verificar que la sala existe
    $room = Room::where('code', $code)->first();

    if (!$room) {
        return false;
    }

    $match = $room->match;
    if (!$match) {
        return false;
    }

    // Buscar el jugador por session_id o user_id
    $sessionId = request()->session()->getId();

    $player = null;

    // Si hay usuario autenticado, buscar por user_id
    if ($user) {
        $player = Player::where('match_id', $match->id)
            ->where('user_id', $user->id)
            ->first();
    }

    // Si no hay player aún, buscar por session_id (para guests)
    if (!$player && $sessionId) {
        $player = Player::where('match_id', $match->id)
            ->where('session_id', $sessionId)
            ->first();
    }

    if (!$player) {
        \Log::warning('❌ Presence Channel: Player not found', [
            'room_code' => $code,
            'user_id' => $user?->id,
            'session_id' => substr($sessionId, 0, 20) . '...',
        ]);
        return false;
    }

    \Log::info('✅ Presence Channel: Authorized', [
        'player_id' => $player->id,
        'player_name' => $player->name,
        'room_code' => $code,
    ]);

    // Retornar info del jugador
    return [
        'id' => $player->id,
        'name' => $player->name,
        'role' => $player->role,
    ];
});

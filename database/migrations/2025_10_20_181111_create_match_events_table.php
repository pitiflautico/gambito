<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->onDelete('cascade'); // Partida donde ocurrió el evento
            $table->string('event_type'); // Tipo de evento (ej: 'draw', 'answer', 'eliminate', 'score_update')
            $table->json('data'); // Datos específicos del evento
            $table->timestamp('created_at'); // Cuándo ocurrió el evento (sin updated_at, es solo log)

            // Índices
            $table->index('match_id');
            $table->index(['match_id', 'event_type']); // Para consultas de eventos específicos
            $table->index('created_at'); // Para consultas temporales
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_events');
    }
};

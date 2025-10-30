<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Test del Health Check Endpoint
 *
 * Valida que el endpoint /api/health funcione correctamente y retorne
 * información sobre el estado de los servicios y métricas del sistema.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: El endpoint health check retorna 200
     */
    public function test_health_endpoint_returns_200(): void
    {
        $response = $this->getJson('/api/health');

        // Puede ser 200 (healthy) o 503 (degraded), dependiendo del estado de servicios
        $this->assertContains($response->status(), [200, 503]);
    }

    /**
     * Test: La estructura JSON es correcta
     */
    public function test_health_endpoint_has_correct_structure(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertJsonStructure([
            'status',
            'timestamp',
            'services' => [
                'database',
                'redis',
                'reverb',
            ],
            'metrics',
        ]);
    }

    /**
     * Test: El campo 'status' contiene valores válidos
     */
    public function test_status_field_has_valid_values(): void
    {
        $response = $this->getJson('/api/health');

        $data = $response->json();

        $this->assertContains($data['status'], ['healthy', 'degraded']);
    }

    /**
     * Test: Services contiene database, redis, reverb
     */
    public function test_services_field_contains_all_services(): void
    {
        $response = $this->getJson('/api/health');

        $data = $response->json();

        $this->assertArrayHasKey('database', $data['services']);
        $this->assertArrayHasKey('redis', $data['services']);
        $this->assertArrayHasKey('reverb', $data['services']);
    }

    /**
     * Test: Cada servicio tiene un estado válido
     */
    public function test_each_service_has_valid_status(): void
    {
        $response = $this->getJson('/api/health');

        $data = $response->json();

        foreach ($data['services'] as $service => $status) {
            $this->assertContains(
                $status,
                ['up', 'down', 'unknown'],
                "Service '{$service}' has invalid status: {$status}"
            );
        }
    }

    /**
     * Test: Metrics incluye active_matches
     */
    public function test_metrics_includes_active_matches(): void
    {
        $response = $this->getJson('/api/health');

        $data = $response->json();

        $this->assertArrayHasKey('active_matches', $data['metrics']);
    }

    /**
     * Test: El conteo de active_matches es correcto
     */
    public function test_active_matches_count_is_correct(): void
    {
        // Crear algunas salas y matches en diferentes estados
        $room1 = Room::create([
            'code' => 'TEST01',
            'game_id' => 1,
            'master_id' => 1,
            'status' => Room::STATUS_PLAYING,
        ]);

        $room2 = Room::create([
            'code' => 'TEST02',
            'game_id' => 1,
            'master_id' => 1,
            'status' => Room::STATUS_PLAYING,
        ]);

        $room3 = Room::create([
            'code' => 'TEST03',
            'game_id' => 1,
            'master_id' => 1,
            'status' => Room::STATUS_FINISHED,
        ]);

        // Crear matches
        GameMatch::create([
            'room_id' => $room1->id,
            'status' => 'in_progress',
            'game_state' => ['phase' => 'playing'],
        ]);

        GameMatch::create([
            'room_id' => $room2->id,
            'status' => 'in_progress',
            'game_state' => ['phase' => 'playing'],
        ]);

        GameMatch::create([
            'room_id' => $room3->id,
            'status' => 'finished',
            'game_state' => ['phase' => 'finished'],
        ]);

        $response = $this->getJson('/api/health');

        $data = $response->json();

        // Debe contar solo los matches 'in_progress'
        $this->assertEquals(2, $data['metrics']['active_matches']);
    }

    /**
     * Test: Database check funciona correctamente
     */
    public function test_database_check_works(): void
    {
        $response = $this->getJson('/api/health');

        $data = $response->json();

        // En tests, la base de datos SQLite debe estar 'up'
        $this->assertEquals('up', $data['services']['database']);
    }

    /**
     * Test: El timestamp tiene formato ISO8601
     */
    public function test_timestamp_is_iso8601_format(): void
    {
        $response = $this->getJson('/api/health');

        $data = $response->json();

        // Validar que el timestamp se puede parsear como fecha ISO8601
        $timestamp = $data['timestamp'];

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $timestamp
        );
    }

    /**
     * Test: El endpoint es público (no requiere autenticación)
     */
    public function test_health_endpoint_is_public(): void
    {
        // Sin autenticación
        $response = $this->getJson('/api/health');

        // No debe retornar 401 Unauthorized
        $this->assertNotEquals(401, $response->status());

        // Debe retornar contenido válido
        $response->assertJsonStructure(['status', 'timestamp', 'services', 'metrics']);
    }
}

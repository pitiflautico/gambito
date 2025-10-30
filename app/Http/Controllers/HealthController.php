<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Health Check Controller
 *
 * Endpoint de monitoreo para verificar el estado del sistema y sus servicios.
 * Usado para health checks de infraestructura y monitoreo de producción.
 */
class HealthController extends Controller
{
    /**
     * Health check endpoint principal.
     *
     * Verifica el estado de todos los servicios críticos y retorna métricas básicas.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $timestamp = now()->toIso8601String();

        // Verificar servicios
        $services = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'reverb' => $this->checkReverb(),
        ];

        // Calcular métricas
        $metrics = $this->collectMetrics();

        // Determinar estado general del sistema
        $allServicesUp = collect($services)->every(fn($status) => $status === 'up');
        $overallStatus = $allServicesUp ? 'healthy' : 'degraded';

        return response()->json([
            'status' => $overallStatus,
            'timestamp' => $timestamp,
            'services' => $services,
            'metrics' => $metrics,
        ], $allServicesUp ? 200 : 503);
    }

    /**
     * Verificar conexión a base de datos.
     *
     * @return string 'up' o 'down'
     */
    protected function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();

            // Verificar que podemos hacer queries
            DB::select('SELECT 1');

            return 'up';
        } catch (\Exception $e) {
            \Log::error('[HealthCheck] Database check failed', [
                'error' => $e->getMessage()
            ]);

            return 'down';
        }
    }

    /**
     * Verificar conexión a Redis.
     *
     * @return string 'up' o 'down'
     */
    protected function checkRedis(): string
    {
        try {
            // Intentar ping a Redis
            $response = Redis::ping();

            // Redis::ping() puede retornar true o "PONG"
            if ($response === true || $response === 'PONG') {
                return 'up';
            }

            return 'down';
        } catch (\Exception $e) {
            \Log::error('[HealthCheck] Redis check failed', [
                'error' => $e->getMessage()
            ]);

            return 'down';
        }
    }

    /**
     * Verificar estado de Reverb (WebSocket server).
     *
     * @return string 'up', 'down', o 'unknown'
     */
    protected function checkReverb(): string
    {
        try {
            // Verificar si el proceso de Reverb está corriendo
            // Buscar proceso "reverb:start"
            $output = [];
            $returnCode = 0;

            exec('pgrep -f "reverb:start"', $output, $returnCode);

            // Si pgrep encontró el proceso, returnCode será 0
            if ($returnCode === 0 && !empty($output)) {
                return 'up';
            }

            // Si no encontró el proceso, considerar como down
            return 'down';
        } catch (\Exception $e) {
            \Log::error('[HealthCheck] Reverb check failed', [
                'error' => $e->getMessage()
            ]);

            // Si hubo un error al verificar, retornar 'unknown'
            return 'unknown';
        }
    }

    /**
     * Recolectar métricas básicas del sistema.
     *
     * @return array
     */
    protected function collectMetrics(): array
    {
        try {
            // Contar matches activos
            $activeMatches = GameMatch::where('status', 'in_progress')->count();

            // Intentar obtener conexiones activas desde Redis/Reverb
            // Esto depende de cómo Reverb almacena la información de presencia
            $activeConnections = $this->countActiveConnections();

            return [
                'active_matches' => $activeMatches,
                'active_connections' => $activeConnections,
            ];
        } catch (\Exception $e) {
            \Log::error('[HealthCheck] Metrics collection failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'active_matches' => null,
                'active_connections' => null,
                'error' => 'Failed to collect metrics',
            ];
        }
    }

    /**
     * Contar conexiones activas en presence channels.
     *
     * @return int|null
     */
    protected function countActiveConnections(): ?int
    {
        try {
            // Buscar todas las keys de presence channels en Redis
            // Reverb/Laravel Echo almacena presence data con pattern "laravel_database_*:presence-*"
            $keys = Redis::keys('*:presence-*');

            if (empty($keys)) {
                return 0;
            }

            $totalConnections = 0;

            foreach ($keys as $key) {
                // Cada presence channel es un hash en Redis
                // Contar miembros en cada canal
                $members = Redis::hlen($key);
                $totalConnections += $members;
            }

            return $totalConnections;
        } catch (\Exception $e) {
            \Log::warning('[HealthCheck] Could not count active connections', [
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Readiness probe for load balancers and orchestrators. Liveness is Laravel's own `/up`; readiness also proves that the
 * database, the cache and the private storage disk answer. It reports only pass or fail per check, never error details.
 */
class HealthController extends Controller
{
    #[OA\Get(
        path: '/api/v1/health/ready',
        operationId: 'getReadiness',
        description: 'Checks the database, the cache and the private storage disk. Unauthenticated and rate limited per address.',
        summary: 'Readiness probe',
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'Every check passed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
                new OA\Property(property: 'checks', type: 'object', example: ['database' => 'ok', 'cache' => 'ok', 'storage' => 'ok']),
            ])),
            new OA\Response(response: 503, description: 'At least one check failed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'degraded'),
                new OA\Property(property: 'checks', type: 'object', example: ['database' => 'fail', 'cache' => 'ok', 'storage' => 'ok']),
            ])),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->passes(static fn (): bool => Tenant::query()->getConnection()->getPdo() !== null),
            'cache' => $this->passes(static function (): bool {
                $key = 'health:ready:'.bin2hex(random_bytes(4));
                Cache::put($key, 'ok', 10);
                $value = Cache::pull($key);

                return $value === 'ok';
            }),
            'storage' => $this->passes(static fn (): bool => is_array(Storage::disk((string) config('backup.disk', 'private'))->directories())),
        ];

        $ready = ! in_array('fail', $checks, true);

        return response()->json(
            ['status' => $ready ? 'ok' : 'degraded', 'checks' => $checks],
            $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * @param  callable(): bool  $check
     */
    private function passes(callable $check): string
    {
        try {
            return $check() ? 'ok' : 'fail';
        } catch (Throwable) {
            return 'fail';
        }
    }
}

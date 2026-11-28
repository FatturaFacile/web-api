<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Beekeeping\Application\Queries\GetTraceabilityHandler;
use Modules\Beekeeping\Application\Queries\GetTraceabilityQuery;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use OpenApi\Attributes as OA;

/**
 * HACCP traceability: forward from an apiary, intake or batch, backward from a jar lot code.
 */
final class TraceabilityController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    public function __construct(private readonly GetTraceabilityHandler $handler) {}

    #[OA\Get(path: '/api/v1/beekeeping/traceability/backward/{lotCode}', operationId: 'beekeepingTraceBackward', summary: 'Trace a jar lot back to batch, supers, apiaries and treatments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'lotCode', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Trace')])]
    public function backward(string $lotCode): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse($this->handler->handle(new GetTraceabilityQuery('backward', $lotCode)));
    }

    #[OA\Get(path: '/api/v1/beekeeping/traceability/forward', operationId: 'beekeepingTraceForward', summary: 'Trace an apiary, intake or batch forward to the jar lots', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Trace')])]
    public function forward(Request $request): JsonResponse
    {
        $this->authorizeRead();
        $type = (string) $request->query('type', 'apiary');
        $id = (string) $request->query('id', '');

        if (! in_array($type, ['apiary', 'batch', 'intake'], true) || $id === '') {
            throw new BeekeepingException('A forward trace needs a type (apiary, batch, intake) and an id.', 'trace_reference_required');
        }

        return $this->successResponse($this->handler->handle(new GetTraceabilityQuery('forward', $id, $type)));
    }
}

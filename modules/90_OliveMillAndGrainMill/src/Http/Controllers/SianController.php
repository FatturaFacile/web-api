<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\OliveMillAndGrainMill\Application\Commands\TransmitSianCommand;
use Modules\OliveMillAndGrainMill\Application\Handlers\TransmitSianHandler;
use Modules\OliveMillAndGrainMill\Application\Queries\MillQueries;
use Modules\OliveMillAndGrainMill\Http\Controllers\Concerns\InteractsWithOliveMill;
use Modules\OliveMillAndGrainMill\Http\Requests\TransmitSianRequest;
use Modules\OliveMillAndGrainMill\Http\Resources\SianMovementResource;
use Modules\OliveMillAndGrainMill\Models\SianMovement;
use OpenApi\Attributes as OA;

/**
 * SIAN telematic oil register: the operations, the six day deadline alerts, the transmission and the official XML / CSV file.
 */
final class SianController extends Controller
{
    use ApiResponse;
    use InteractsWithOliveMill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/olive-mill/sian-movements', operationId: 'oliveMillSianIndex', summary: 'Operations of the oil register with the days left to the SIAN deadline', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Operations')])]
    public function index(MillQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', SianMovement::class);

        return $this->paged($queries->movements($this->filters(), $this->perPage()), SianMovementResource::class);
    }

    #[OA\Get(path: '/api/v1/olive-mill/sian-movements/compliance-alerts', operationId: 'oliveMillSianAlerts', summary: 'Untransmitted operations that are overdue or due within the alert window', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Alerts')])]
    public function alerts(MillQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', SianMovement::class);

        return $this->successResponse($queries->complianceAlerts(), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/sian-movements/transmit', operationId: 'oliveMillSianTransmit', summary: 'Validate and transmit the pending operations to the SIAN', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Transmission outcome')])]
    public function transmit(TransmitSianRequest $request, TransmitSianHandler $handler): JsonResponse
    {
        Gate::authorize('create', SianMovement::class);

        $result = $handler->handle(TransmitSianCommand::fromArray($request->validated(), $this->userId()));

        return $this->successResponse($result, $this->message($result['accepted'] ? 'sian_transmitted' : 'sian_not_transmitted'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/sian-movements/export/{format}', operationId: 'oliveMillSianExport', summary: 'Download the pending operations as the official XML or CSV file', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'File')])]
    public function export(TransmitSianRequest $request, string $format, TransmitSianHandler $handler): Response
    {
        Gate::authorize('create', SianMovement::class);

        $file = $handler->export(TransmitSianCommand::fromArray($request->validated(), $this->userId()), $format);

        return response($file['content'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"']);
    }
}

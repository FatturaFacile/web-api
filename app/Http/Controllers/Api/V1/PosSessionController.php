<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Retail\ClosePosSession;
use App\Actions\Retail\OpenPosSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retail\ClosePosSessionRequest;
use App\Http\Requests\Retail\OpenPosSessionRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\PosSession;
use App\Services\Retail\PosSessionReport;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class PosSessionController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/retail/pos/sessions',
        operationId: 'getPosSessions',
        description: 'List paginated retail point-of-sale cashier sessions.',
        summary: 'List POS sessions',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'POS sessions retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $sessions = PosSession::latest('opened_at')->paginate(25);

        return $this->successResponse(data: $sessions, message: 'POS sessions retrieved');
    }

    #[OA\Post(
        path: '/api/v1/retail/pos/sessions',
        operationId: 'openPosSession',
        description: 'Open a new POS cashier session with specified opening float.',
        summary: 'Open POS session',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['pos_terminal_id'],
                properties: [
                    new OA\Property(property: 'pos_terminal_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'opening_float', type: 'number', format: 'float', default: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Session opened successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(OpenPosSessionRequest $request, OpenPosSession $openSession): JsonResponse
    {
        $session = $openSession->execute($request->validated('pos_terminal_id'), (float) $request->validated('opening_float', 0));

        return $this->successResponse(new CatalogResource($session), __('pos.messages.session_opened'), Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/retail/pos/sessions/{id}/close',
        operationId: 'closePosSession',
        description: 'Close active POS cashier session and reconcile counted cash against expected float.',
        summary: 'Close POS session',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['closing_cash'],
                properties: [
                    new OA\Property(property: 'closing_cash', type: 'number', format: 'float'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Session closed successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Session not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function close(ClosePosSessionRequest $request, ClosePosSession $closeSession, string $id): JsonResponse
    {
        $session = $closeSession->execute(PosSession::findOrFail($id), (float) $request->validated('closing_cash'));

        return $this->successResponse(new CatalogResource($session), __('pos.messages.session_closed'));
    }

    #[OA\Get(
        path: '/api/v1/retail/pos/sessions/{id}/report',
        operationId: 'getPosSessionReport',
        description: 'Generate financial summary report for a closed or active POS session.',
        summary: 'POS session report',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Session report generated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Session not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function report(PosSessionReport $report, string $id): JsonResponse
    {
        return $this->successResponse($report->build(PosSession::findOrFail($id)), __('pos.messages.session_report'));
    }
}

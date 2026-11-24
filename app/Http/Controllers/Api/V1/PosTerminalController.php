<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retail\StorePosTerminalRequest;
use App\Models\PosTerminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PosTerminalController extends Controller
{
    use HandlesLookupCrud {
        index as lookupIndex;
    }

    protected function modelClass(): string
    {
        return PosTerminal::class;
    }

    protected function label(): string
    {
        return __('pos.terminal_label');
    }

    #[OA\Get(
        path: '/api/v1/retail/pos/terminals',
        operationId: 'getPosTerminals',
        description: 'List registered retail POS physical or mobile terminals.',
        summary: 'List POS terminals',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Terminals retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->lookupIndex($request);
    }

    #[OA\Post(
        path: '/api/v1/retail/pos/terminals',
        operationId: 'storePosTerminal',
        description: 'Register a new POS physical or mobile terminal.',
        summary: 'Register POS terminal',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'code'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Front Desk Register 1'),
                    new OA\Property(property: 'code', type: 'string', example: 'POS-01'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Terminal registered successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StorePosTerminalRequest $request): JsonResponse
    {
        return $this->createRecord($request->validated());
    }
}

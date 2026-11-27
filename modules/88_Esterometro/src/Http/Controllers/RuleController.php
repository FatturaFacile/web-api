<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Esterometro\Http\Requests\StoreClassificationRuleRequest;
use Modules\Esterometro\Http\Resources\SelfInvoiceRuleResource;
use Modules\Esterometro\Models\SelfInvoiceClassificationRule;
use OpenApi\Attributes as OA;

final class RuleController extends Controller
{
    #[OA\Get(
        path: '/api/v1/esterometro/rules',
        summary: 'List global and tenant-specific TD16-TD28 classification rules',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Classification rules')],
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;

        $rules = SelfInvoiceClassificationRule::query()
            ->where(static fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByRaw('CASE WHEN tenant_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('priority')
            ->get();

        return SelfInvoiceRuleResource::collection($rules)->response();
    }

    #[OA\Post(
        path: '/api/v1/esterometro/rules',
        summary: 'Create a tenant-specific TD16-TD28 classification rule',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 201, description: 'Rule created')],
    )]
    public function store(StoreClassificationRuleRequest $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $rule = SelfInvoiceClassificationRule::create($request->validated() + [
            'tenant_id' => $tenantId,
            'soggetto_emittente' => $request->input('soggetto_emittente', 'CC'),
            'priority' => $request->input('priority', 50),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return (new SelfInvoiceRuleResource($rule))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/esterometro/rules/{id}',
        summary: 'Update a tenant-specific TD16-TD28 classification rule',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Rule updated')],
    )]
    public function update(StoreClassificationRuleRequest $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $rule = SelfInvoiceClassificationRule::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $rule->update($request->validated());

        return (new SelfInvoiceRuleResource($rule->fresh() ?? $rule))->response();
    }

    #[OA\Delete(
        path: '/api/v1/esterometro/rules/{id}',
        summary: 'Delete a tenant-specific TD16-TD28 classification rule',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 204, description: 'Rule deleted')],
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $rule = SelfInvoiceClassificationRule::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $rule->delete();

        return response()->json(null, 204);
    }
}

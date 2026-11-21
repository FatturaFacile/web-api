<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\AiCognitive\Application\Handlers\GetAiUsageAndAuditHandler;
use Modules\AiCognitive\Application\Queries\GetAiUsageAndAuditQuery;
use Modules\AiCognitive\Presentation\Http\Requests\AiListRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class AiAuditController extends AiApiController
{
    #[OA\Get(path: '/api/v1/ai/usage-audit', operationId: 'getAiUsageAndAudit', description: 'Token and cost telemetry by provider and the immutable audit trail (inferences, suggestions, confidence scores, citations and human decisions) of a period, with an optional verification of the hash chain (GeBüV / Olico, EU AI Act transparency).', summary: 'AI usage, cost and audit trail', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 200, description: 'Usage and audit', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function index(AiListRequest $request, GetAiUsageAndAuditHandler $handler): JsonResponse
    {
        $data = $request->validated();
        $result = $handler->handle(new GetAiUsageAndAuditQuery($this->tenantId(), $data['from'] ?? null, $data['to'] ?? null, $data['event_type'] ?? null, (int) ($data['per_page'] ?? 50), (bool) ($data['verify_chain'] ?? false)));

        return $this->successResponse([
            'period' => $result['period'],
            'usage' => $result['usage'],
            'chain' => $result['chain'],
            'audit' => array_map(static fn ($row): array => [
                'sequence' => $row->sequence,
                'event_type' => $row->event_type,
                'actor_user_id' => $row->actor_user_id,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'provider' => $row->provider,
                'model' => $row->model,
                'confidence' => $row->confidence === null ? null : (float) $row->confidence,
                'payload' => $row->payload_json,
                'hash' => $row->hash,
                'created_at' => $row->created_at?->toIso8601String(),
            ], $result['audit']->items()),
        ], 'AI usage and audit trail', Response::HTTP_OK, $this->pageMeta($result['audit']));
    }
}

<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Modules\ServiceManagement\Contracts\RmaServiceInterface;
use Modules\ServiceManagement\Enums\EstimateApprovalStatus;
use Modules\ServiceManagement\Enums\RmaStatus;
use Modules\ServiceManagement\Http\Requests\PublicEstimateAcceptRequest;
use Modules\ServiceManagement\Http\Requests\PublicEstimateDeclineRequest;
use Modules\ServiceManagement\Http\Requests\PublicRmaTrackingRequest;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\RmaLine;
use Modules\ServiceManagement\Models\RmaStatusHistory;
use Modules\ServiceManagement\Services\RmaService;
use OpenApi\Attributes as OA;

/**
 * Customer-facing, token-authenticated RMA endpoints (no Sanctum). The tenant is resolved from the RMA behind
 * the token. Any unknown, mismatching or expired token yields 404. Internal costs and lab notes are never exposed.
 */
final class PublicRmaController extends Controller
{
    /**
     * Customer-visible workflow steps (cancelled is not a step).
     */
    private const TRACKING_STEPS = [
        RmaStatus::AwaitingArrival,
        RmaStatus::InDiagnosis,
        RmaStatus::AwaitingEstimateApproval,
        RmaStatus::AwaitingParts,
        RmaStatus::InRepairTesting,
        RmaStatus::ReadyForReturn,
        RmaStatus::Returned,
        RmaStatus::Closed,
    ];

    public function __construct(
        private readonly RmaServiceInterface $rmaService,
    ) {}

    #[OA\Get(
        path: '/api/v1/public/rma/estimates/{token}',
        operationId: 'showPublicRmaEstimate',
        description: 'Token-authenticated customer view of the repair estimate; internal costs and lab notes are never exposed. Unknown or expired tokens return 404.',
        summary: 'Show RMA estimate (public)',
        security: [],
        tags: ['Public RMA'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer estimate token', schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Estimate', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'rma_number', type: 'string'), new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'serial_number', type: 'string', nullable: true), new OA\Property(property: 'product_name', type: 'string', nullable: true), new OA\Property(property: 'reported_symptom', type: 'string'), new OA\Property(property: 'diagnosis_summary', type: 'string', nullable: true), new OA\Property(property: 'warranty_status', type: 'string'), new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'labour_total', type: 'string'), new OA\Property(property: 'parts_total', type: 'string'), new OA\Property(property: 'total', type: 'string'), new OA\Property(property: 'currency', type: 'string'), new OA\Property(property: 'decision', type: 'string', nullable: true), new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'company_name', type: 'string', nullable: true)])])), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function showEstimate(string $token): JsonResponse
    {
        $rma = $this->rmaService->resolveByEstimateToken($token);
        $rma->load(['passport.product', 'lines']);
        $tenant = Tenant::query()->find($rma->tenant_id);

        $lines = $rma->lines
            ->map(static fn (RmaLine $line): array => [
                'description' => $line->description,
                'qty' => $line->qty,
                'unit_price' => $line->unit_price,
                'total_price' => $line->totalPrice(),
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'rma_number' => $rma->rma_number,
                'status' => $rma->status,
                'serial_number' => $rma->passport?->serial_number,
                'product_name' => $rma->passport?->product?->name,
                'reported_symptom' => $rma->reported_symptom,
                'diagnosis_summary' => $this->diagnosisSummary($rma),
                'warranty_status' => $rma->warranty_status,
                'lines' => $lines,
                'labour_total' => $rma->estimate_labour_total,
                'parts_total' => $rma->estimate_parts_total,
                'total' => $rma->estimate_total,
                'currency' => $rma->currency,
                'decision' => $rma->estimateApprovalStatusEnum()->publicDecision(),
                'expires_at' => $rma->estimate_token_expires_at?->toIso8601String(),
                'company_name' => $tenant?->legal_name ?: $tenant?->name,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/public/rma/estimates/{token}/accept',
        operationId: 'acceptPublicRmaEstimate',
        description: 'Customer accepts the estimate through the token link.',
        summary: 'Accept RMA estimate (public)',
        security: [],
        tags: ['Public RMA'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer estimate token', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['accepted_by_name'], properties: [new OA\Property(property: 'accepted_by_name', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Estimate approved', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'decision', type: 'string'), new OA\Property(property: 'status', type: 'string')])])), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function acceptEstimate(PublicEstimateAcceptRequest $request, string $token): JsonResponse
    {
        $rma = $this->rmaService->resolveByEstimateToken($token);

        $updated = $this->rmaService->approveEstimate(
            $rma,
            RmaService::DECIDED_VIA_PUBLIC_LINK,
            null,
            (string) $request->validated('accepted_by_name'),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Estimate approved. Thank you.',
            'data' => ['decision' => 'approved', 'status' => $updated->status],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/public/rma/estimates/{token}/decline',
        operationId: 'declinePublicRmaEstimate',
        description: 'Customer declines the estimate through the token link; the unit is returned unrepaired.',
        summary: 'Decline RMA estimate (public)',
        security: [],
        tags: ['Public RMA'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer estimate token', schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['declined_by_name'], properties: [new OA\Property(property: 'declined_by_name', type: 'string'), new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Estimate declined', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'decision', type: 'string'), new OA\Property(property: 'status', type: 'string')])])), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function declineEstimate(PublicEstimateDeclineRequest $request, string $token): JsonResponse
    {
        $rma = $this->rmaService->resolveByEstimateToken($token);

        $updated = $this->rmaService->declineEstimate(
            $rma,
            RmaService::DECIDED_VIA_PUBLIC_LINK,
            null,
            (string) $request->validated('declined_by_name'),
            $request->ip(),
            $request->validated('reason'),
        );

        return response()->json([
            'message' => 'Estimate declined. The unit will be returned unrepaired.',
            'data' => ['decision' => 'declined', 'status' => $updated->status],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/public/rma/tracking',
        operationId: 'trackPublicRma',
        description: 'Token-authenticated customer tracking of the RMA workflow steps.',
        summary: 'Track RMA (public)',
        security: [],
        tags: ['Public RMA'],
        parameters: [new OA\Parameter(name: 'rma_number', in: 'query', required: true, description: 'RMA number', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'token', in: 'query', required: true, description: 'Customer tracking token', schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Tracking status', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'rma_number', type: 'string'), new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'status_label', type: 'string'), new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'estimate_pending', type: 'boolean'), new OA\Property(property: 'estimate_url', type: 'string', nullable: true), new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true)])])), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function tracking(PublicRmaTrackingRequest $request): JsonResponse
    {
        $rma = $this->rmaService->resolveByTrackingToken(
            (string) $request->validated('rma_number'),
            (string) $request->validated('token'),
        );
        $rma->load('history');

        $reachedAt = [];
        foreach ($rma->history as $entry) {
            /** @var RmaStatusHistory $entry */
            $reachedAt[$entry->to_status] ??= $entry->created_at?->toIso8601String();
        }

        $steps = array_map(static fn (RmaStatus $status): array => [
            'status' => $status->value,
            'label' => $status->label(),
            'reached_at' => $reachedAt[$status->value] ?? null,
            'current' => $rma->status === $status->value,
        ], self::TRACKING_STEPS);

        return response()->json([
            'data' => [
                'rma_number' => $rma->rma_number,
                'status' => $rma->status,
                'status_label' => $rma->statusEnum()->label(),
                'steps' => $steps,
                'estimate_pending' => $rma->estimate_approval_status === EstimateApprovalStatus::PendingCustomerApproval->value,
                'estimate_url' => null,
                'updated_at' => $rma->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Customer-facing summary built from the estimate line descriptions (lab bench notes stay internal).
     */
    private function diagnosisSummary(DepotRepairRmaOrder $rma): ?string
    {
        $descriptions = $rma->lines->pluck('description')->filter()->unique()->values()->all();

        return $descriptions === [] ? null : implode('; ', $descriptions);
    }
}

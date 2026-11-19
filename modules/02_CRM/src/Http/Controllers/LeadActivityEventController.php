<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\LeadEventServiceInterface;
use Modules\CRM\Http\Requests\StoreLeadActivityEventRequest;
use Modules\CRM\Http\Resources\LeadActivityEventResource;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadActivityEvent;
use OpenApi\Attributes as OA;

final class LeadActivityEventController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LeadEventServiceInterface $events,
    ) {}

    #[OA\Get(
        path: '/api/v1/crm/leads/{id}/events',
        operationId: 'listCrmLeadEvents',
        summary: 'List lead behavioural events',
        tags: ['CRM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Lead events')]
    )]
    public function index(Request $request, string $id): JsonResponse
    {
        $lead = $this->findLead($id);
        $this->authorize('view', $lead);

        $query = LeadActivityEvent::query()
            ->where('tenant_id', $lead->tenant_id)
            ->where('lead_id', $lead->id);

        if ($request->filled('type')) {
            $query->where('type', (string) $request->query('type'));
        }

        $events = $query->orderByDesc('occurred_at')->paginate(50);

        return response()->json([
            'data' => LeadActivityEventResource::collection($events->getCollection()),
            'meta' => [
                'current_page' => $events->currentPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /**
     * Records a behavioural event and re-scores the lead (201). Replaying an external_id returns 200.
     */
    #[OA\Post(
        path: '/api/v1/crm/leads/{id}/events',
        operationId: 'storeCrmLeadEvent',
        summary: 'Record lead behavioural event',
        tags: ['CRM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 201, description: 'Lead event recorded')]
    )]
    public function store(StoreLeadActivityEventRequest $request, string $id): JsonResponse
    {
        $lead = $this->findLead($id);
        $this->authorize('update', $lead);

        $occurredAt = $request->filled('occurred_at') ? CarbonImmutable::parse((string) $request->input('occurred_at')) : null;
        $metadata = $request->input('metadata');

        $result = $this->events->record(
            $lead,
            (string) $request->input('type'),
            $occurredAt,
            is_array($metadata) ? $metadata : [],
            $request->filled('external_id') ? (string) $request->input('external_id') : null,
        );

        $scored = $result['lead'];

        return response()->json([
            'data' => (new LeadActivityEventResource($result['event']))->resolve($request),
            'lead' => [
                'id' => (string) $scored->id,
                'score' => (int) $scored->score,
                'rating' => $scored->rating,
                'score_breakdown' => $scored->score_breakdown,
            ],
        ], $result['created'] ? 201 : 200);
    }

    private function findLead(string $id): Lead
    {
        return Lead::query()->where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }
}

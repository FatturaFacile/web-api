<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\CRM\Contracts\LeadMergeServiceInterface;
use Modules\CRM\Exceptions\DuplicateResolutionException;
use Modules\CRM\Http\Requests\MergeLeadDuplicateRequest;
use Modules\CRM\Http\Resources\LeadDuplicateCandidateResource;
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Models\LeadDuplicateCandidate;

final class LeadDuplicateController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LeadMergeServiceInterface $merger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeadDuplicateCandidate::class);

        $request->validate([
            'status' => ['nullable', 'string', Rule::in(LeadDuplicateCandidate::STATUSES)],
            'lead_id' => ['nullable', 'uuid'],
            'match_type' => ['nullable', 'string', Rule::in(LeadDuplicateCandidate::MATCH_TYPES)],
        ]);

        $query = LeadDuplicateCandidate::query()
            ->with(['lead.leadSource', 'candidateLead', 'contact', 'account'])
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->where('status', (string) $request->query('status', 'pending'));

        if ($request->filled('lead_id')) {
            $query->where('lead_id', (string) $request->query('lead_id'));
        }

        if ($request->filled('match_type')) {
            $query->where('match_type', (string) $request->query('match_type'));
        }

        $candidates = $query->orderByDesc('confidence')->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => LeadDuplicateCandidateResource::collection($candidates->getCollection()),
            'meta' => [
                'current_page' => $candidates->currentPage(),
                'total' => $candidates->total(),
            ],
        ]);
    }

    public function merge(MergeLeadDuplicateRequest $request, string $id): JsonResponse
    {
        $candidate = $this->findCandidate($id);
        $this->authorize('update', $candidate);

        try {
            $survivor = $this->merger->mergeCandidate(
                $candidate,
                (string) $request->input('survivor'),
                $request->fieldChoices(),
                (string) $request->user()?->getAuthIdentifier(),
            );
        } catch (DuplicateResolutionException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        $mergedLeadId = (string) $survivor->id === (string) $candidate->lead_id ? $candidate->candidate_lead_id : $candidate->lead_id;

        return response()->json([
            'data' => [
                'id' => (string) $candidate->id,
                'status' => 'merged',
                'survivor_lead' => (new LeadResource($survivor))->resolve($request),
                'merged_lead_id' => $mergedLeadId,
            ],
        ]);
    }

    public function dismiss(Request $request, string $id): JsonResponse
    {
        $candidate = $this->findCandidate($id);
        $this->authorize('update', $candidate);

        try {
            $dismissed = $this->merger->dismissCandidate($candidate, (string) $request->user()?->getAuthIdentifier());
        } catch (DuplicateResolutionException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        $dismissed->load(['lead', 'candidateLead', 'contact', 'account']);

        return (new LeadDuplicateCandidateResource($dismissed))->response();
    }

    private function findCandidate(string $id): LeadDuplicateCandidate
    {
        return LeadDuplicateCandidate::query()->where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }
}

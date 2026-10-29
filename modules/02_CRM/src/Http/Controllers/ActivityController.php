<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\ActivityServiceInterface;
use Modules\CRM\DTOs\CreateActivityDTO;
use Modules\CRM\Http\Requests\StoreActivityRequest;
use Modules\CRM\Http\Resources\ActivityResource;
use Modules\CRM\Models\Activity;

final class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityServiceInterface $activityService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Activity::where('tenant_id', $tenant->id);

        if ($request->has('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $activities = $query->orderBy('due_date')->paginate(25);

        return response()->json([
            'data' => ActivityResource::collection($activities),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'total' => $activities->total(),
            ],
        ]);
    }

    public function store(StoreActivityRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? 'system');

        $activity = $this->activityService->logActivity(new CreateActivityDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            subject: $request->input('subject'),
            type: $request->input('type'),
            priority: $request->input('priority', 'medium'),
            dueDate: $request->input('due_date'),
            assignedUserId: $request->input('assigned_user_id'),
            relatedType: $request->input('related_type'),
            relatedId: $request->input('related_id'),
            durationMinutes: $request->input('duration_minutes') ? (int) $request->input('duration_minutes') : null,
            location: $request->input('location'),
            notes: $request->input('notes'),
            metadata: $request->input('metadata', [])
        ), $actorId);

        return response()->json(new ActivityResource($activity), 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $activity = Activity::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new ActivityResource($activity));
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $activity = Activity::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $completed = $this->activityService->completeActivity(
            $activity,
            $request->input('outcome'),
            $actorId
        );

        return response()->json(new ActivityResource($completed));
    }

    public function timeline(Request $request): JsonResponse
    {
        $request->validate([
            'related_type' => ['required', 'string'],
            'related_id' => ['required', 'uuid'],
        ]);

        $tenant = $this->tenantContext->getTenant();
        $timeline = $this->activityService->getTimeline(
            (string) $tenant->id,
            $request->query('related_type'),
            $request->query('related_id')
        );

        return response()->json(ActivityResource::collection($timeline));
    }
}

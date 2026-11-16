<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Http\Requests\AssignRequestRequest;
use Modules\Facility\Http\Requests\LinkWorkOrderRequest;
use Modules\Facility\Http\Requests\RaiseWorkOrderRequest;
use Modules\Facility\Http\Requests\ReasonRequest;
use Modules\Facility\Http\Requests\RequestCommentRequest;
use Modules\Facility\Http\Requests\ResolveRequestRequest;
use Modules\Facility\Http\Requests\ServiceRequestRequest;
use Modules\Facility\Http\Resources\RequestCommentResource;
use Modules\Facility\Http\Resources\ServiceRequestResource;
use Modules\Facility\Models\FacilityRequestComment;
use Modules\Facility\Models\FacilityServiceRequest;
use Modules\Facility\Policies\FacilityPolicy;
use Modules\Facility\Services\ServiceRequestService;

/**
 * Facility service requests: the list and board, the status flow, the assignment, the comments and the link to the
 * maintenance work orders of the asset management module. Members see their own requests; operators see all of them.
 * Operations are documented in FacilityRequestOpenApi.
 */
final class ServiceRequestController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly ServiceRequestService $requests, private readonly FacilityPolicy $policy) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FacilityServiceRequest::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(FacilityServiceRequest::Statuses)],
            'category' => ['nullable', Rule::in(FacilityServiceRequest::Categories)],
            'priority' => ['nullable', Rule::in(FacilityServiceRequest::Priorities)],
            'assignee_id' => ['nullable', 'uuid'],
            'building_id' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],
            'open' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return ServiceRequestResource::collection(
            $this->visibleRequests()
                ->with(['requester:id,name', 'assignee:id,name'])
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['category']), static fn ($query) => $query->where('category', $filters['category']))
                ->when(isset($filters['priority']), static fn ($query) => $query->where('priority', $filters['priority']))
                ->when(isset($filters['assignee_id']), static fn ($query) => $query->where('assignee_id', $filters['assignee_id']))
                ->when(isset($filters['building_id']), static fn ($query) => $query->where('building_id', $filters['building_id']))
                ->when($request->boolean('mine'), fn ($query) => $query->where('requester_id', $this->actorId()))
                ->when($request->boolean('open'), static fn ($query) => $query->whereIn('status', FacilityServiceRequest::OpenStatuses))
                ->when($request->boolean('overdue'), static fn ($query) => $query->whereIn('status', FacilityServiceRequest::OpenStatuses)->where('due_at', '<', now()))
                ->when(isset($filters['q']), static fn ($query) => $query->where(static fn ($inner) => $inner->where('title', 'like', '%'.$filters['q'].'%')->orWhere('number', 'like', '%'.$filters['q'].'%')))
                ->orderByDesc('created_at')
                ->gridPaginate($this->perPage())
        );
    }

    public function board(): JsonResponse
    {
        $this->authorize('viewAny', FacilityServiceRequest::class);

        return response()->json(['data' => $this->requests->board($this->policy->isOperator($this->actor()) ? null : $this->actorId())]);
    }

    public function store(ServiceRequestRequest $request): JsonResponse
    {
        $this->authorize('create', FacilityServiceRequest::class);
        $created = $this->requests->create($this->tenantId(), $request->validated(), $this->actorId());

        return (new ServiceRequestResource($created->load(['requester:id,name', 'assignee:id,name'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): ServiceRequestResource
    {
        return new ServiceRequestResource($this->viewable($id)->load(['requester:id,name', 'assignee:id,name']));
    }

    public function update(ServiceRequestRequest $request, string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorizeRequesterOrOperator($serviceRequest, onlyWhileNew: true);

        return new ServiceRequestResource($this->requests->update($serviceRequest, $request->validated())->load(['requester:id,name', 'assignee:id,name']));
    }

    public function assign(AssignRequestRequest $request, string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorize('operate', $serviceRequest);

        return $this->respond($this->requests->assign($serviceRequest, (string) $request->validated('assignee_id')));
    }

    public function start(string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorize('operate', $serviceRequest);

        return $this->respond($this->requests->start($serviceRequest, $this->actorId()));
    }

    public function hold(ReasonRequest $request, string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorize('operate', $serviceRequest);

        return $this->respond($this->requests->hold($serviceRequest, (string) $request->validated('reason')));
    }

    public function resolve(ResolveRequestRequest $request, string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorize('operate', $serviceRequest);

        return $this->respond($this->requests->resolve($serviceRequest, (string) $request->validated('resolution_notes')));
    }

    public function close(string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorize('operate', $serviceRequest);

        return $this->respond($this->requests->close($serviceRequest));
    }

    public function reopen(string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorizeRequesterOrOperator($serviceRequest);

        return $this->respond($this->requests->reopen($serviceRequest, $this->actorId()));
    }

    public function cancel(ReasonRequest $request, string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorizeRequesterOrOperator($serviceRequest, onlyWhileNew: true);

        return $this->respond($this->requests->cancel($serviceRequest, (string) $request->validated('reason')));
    }

    public function linkWorkOrder(LinkWorkOrderRequest $request, string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorize('operate', $serviceRequest);

        return $this->respond($this->requests->linkWorkOrder($serviceRequest, (string) $request->validated('work_order_id')));
    }

    public function raiseWorkOrder(RaiseWorkOrderRequest $request, string $id): ServiceRequestResource
    {
        $serviceRequest = $this->find($id);
        $this->authorize('operate', $serviceRequest);

        return $this->respond($this->requests->raiseWorkOrder($serviceRequest, $request->validated('asset_id'), $this->actorId()));
    }

    public function comments(string $id): AnonymousResourceCollection
    {
        $serviceRequest = $this->viewable($id);
        $internal = $this->policy->isOperator($this->actor());

        return RequestCommentResource::collection(
            FacilityRequestComment::query()
                ->with('author:id,name')
                ->where('service_request_id', $serviceRequest->id)
                ->when(! $internal, static fn ($query) => $query->where('is_internal', false))
                ->orderBy('created_at')
                ->get()
        );
    }

    public function addComment(RequestCommentRequest $request, string $id): JsonResponse
    {
        $serviceRequest = $this->viewable($id);
        $internal = $this->policy->isOperator($this->actor()) && (bool) $request->validated('is_internal', false);

        $comment = $this->requests->comment($serviceRequest, $this->actorId(), (string) $request->validated('body'), $internal);

        return (new RequestCommentResource($comment->load('author:id,name')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * @return Builder<FacilityServiceRequest>
     */
    private function visibleRequests(): Builder
    {
        return FacilityServiceRequest::query()->when(
            ! $this->policy->isOperator($this->actor()),
            fn ($query) => $query->where('requester_id', $this->actorId())
        );
    }

    private function find(string $id): FacilityServiceRequest
    {
        return FacilityServiceRequest::query()->findOrFail($id);
    }

    private function viewable(string $id): FacilityServiceRequest
    {
        $serviceRequest = $this->find($id);
        $this->authorize('view', $serviceRequest);

        return $serviceRequest;
    }

    /**
     * Operators always; the person who raised the request only while it is new when $onlyWhileNew is set.
     */
    private function authorizeRequesterOrOperator(FacilityServiceRequest $serviceRequest, bool $onlyWhileNew = false): void
    {
        if ($this->policy->operate($this->actor(), $serviceRequest)) {
            return;
        }

        abort_unless($this->policy->requester($this->actor(), $serviceRequest) && (! $onlyWhileNew || $serviceRequest->status === 'new'), 403);
    }

    private function respond(FacilityServiceRequest $serviceRequest): ServiceRequestResource
    {
        return new ServiceRequestResource($serviceRequest->load(['requester:id,name', 'assignee:id,name']));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Facility\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\DTOs\CreateMaintenanceWorkOrderDTO;
use Modules\Facility\Models\FacilityRequestComment;
use Modules\Facility\Models\FacilityServiceRequest;
use Modules\Facility\Models\FacilitySpace;

/**
 * Life of a facility service request: new, assigned, in progress, on hold, resolved, closed or cancelled. The SLA due
 * date follows the priority (config facility.sla_hours) and is recomputed from the creation time when the priority
 * changes. A request can be linked to an existing maintenance work order of the asset management module or can raise one.
 */
final readonly class ServiceRequestService
{
    public function __construct(
        private FacilityNumbers $numbers,
        private FacilityLedger $ledger,
        private MaintenanceManagementServiceInterface $maintenance,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $requesterId): FacilityServiceRequest
    {
        $data = $this->withLocation($data);
        $priority = (string) ($data['priority'] ?? 'normal');

        return DB::transaction(function () use ($tenantId, $data, $priority, $requesterId): FacilityServiceRequest {
            $request = FacilityServiceRequest::create([
                'tenant_id' => $tenantId,
                'number' => $this->numbers->next('fac_service_requests', 'SR', $tenantId),
                'category' => $data['category'],
                'priority' => $priority,
                'status' => 'new',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'building_id' => $data['building_id'] ?? null,
                'space_id' => $data['space_id'] ?? null,
                'requester_id' => $requesterId,
                'asset_id' => $data['asset_id'] ?? null,
                'inspection_id' => $data['inspection_id'] ?? null,
                'due_at' => $this->dueAt(CarbonImmutable::now(), $priority),
            ]);

            $this->ledger->record('FACILITY_REQUEST_RAISED', $request, ['category' => $request->category, 'priority' => $request->priority]);

            return $request;
        });
    }

    /**
     * Changes the description of an open request.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FacilityServiceRequest $request, array $data): FacilityServiceRequest
    {
        $this->assertOpen($request);
        $location = $this->withLocation([
            'building_id' => array_key_exists('building_id', $data) ? $data['building_id'] : $request->building_id,
            'space_id' => array_key_exists('space_id', $data) ? $data['space_id'] : $request->space_id,
        ]);
        $data = array_merge($data, $location);
        $fields = array_intersect_key($data, array_flip(['title', 'description', 'category', 'priority', 'building_id', 'space_id', 'asset_id']));

        if (isset($fields['priority']) && $fields['priority'] !== $request->priority) {
            $fields['due_at'] = $this->dueAt(CarbonImmutable::instance($request->created_at), (string) $fields['priority']);
        }

        $request->update($fields);

        return $request->refresh();
    }

    public function assign(FacilityServiceRequest $request, string $assigneeId): FacilityServiceRequest
    {
        return DB::transaction(function () use ($request, $assigneeId): FacilityServiceRequest {
            $locked = $this->lock($request);
            $this->assertOpen($locked);

            $fields = ['assignee_id' => $assigneeId, 'assigned_at' => now(), 'responded_at' => $locked->responded_at ?? now()];
            if ($locked->status === 'new') {
                $fields['status'] = 'assigned';
            }

            $locked->update($fields);
            $this->ledger->record('FACILITY_REQUEST_ASSIGNED', $locked, ['assignee_id' => $assigneeId, 'status' => $locked->status]);

            return $locked->refresh();
        });
    }

    public function start(FacilityServiceRequest $request, ?string $actorId): FacilityServiceRequest
    {
        return $this->transition($request, 'in_progress', 'FACILITY_REQUEST_STARTED', static fn (FacilityServiceRequest $locked): array => [
            'assignee_id' => $locked->assignee_id ?? $actorId,
            'responded_at' => $locked->responded_at ?? now(),
            'resolved_at' => null,
            'hold_reason' => null,
        ]);
    }

    public function reopen(FacilityServiceRequest $request, ?string $actorId): FacilityServiceRequest
    {
        if ($request->status !== 'resolved') {
            throw ValidationException::withMessages(['status' => [__('facility_errors.request.transition', ['from' => $request->status, 'to' => 'in_progress'])]]);
        }

        return $this->start($request, $actorId);
    }

    public function hold(FacilityServiceRequest $request, string $reason): FacilityServiceRequest
    {
        return $this->transition($request, 'on_hold', 'FACILITY_REQUEST_ON_HOLD', static fn (): array => ['hold_reason' => $reason]);
    }

    public function resolve(FacilityServiceRequest $request, string $notes): FacilityServiceRequest
    {
        return $this->transition($request, 'resolved', 'FACILITY_REQUEST_RESOLVED', static fn (): array => ['resolution_notes' => $notes, 'resolved_at' => now()]);
    }

    public function close(FacilityServiceRequest $request): FacilityServiceRequest
    {
        return $this->transition($request, 'closed', 'FACILITY_REQUEST_CLOSED', static fn (): array => ['closed_at' => now()]);
    }

    public function cancel(FacilityServiceRequest $request, string $reason): FacilityServiceRequest
    {
        return $this->transition($request, 'cancelled', 'FACILITY_REQUEST_CANCELLED', static fn (): array => ['resolution_notes' => $reason, 'closed_at' => now()]);
    }

    /**
     * Links an existing maintenance work order of the asset management module.
     */
    public function linkWorkOrder(FacilityServiceRequest $request, string $workOrderId): FacilityServiceRequest
    {
        return DB::transaction(function () use ($request, $workOrderId): FacilityServiceRequest {
            $locked = $this->lock($request);

            if (in_array($locked->status, ['closed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => [__('facility_errors.request.locked', ['status' => $locked->status])]]);
            }

            $workOrder = DB::table('eam_maintenance_work_orders')
                ->where('tenant_id', $locked->tenant_id)
                ->where('id', $workOrderId)
                ->whereNull('deleted_at')
                ->first(['id', 'asset_id']);

            if ($workOrder === null) {
                throw ValidationException::withMessages(['work_order_id' => [__('facility_errors.request.work_order_not_found')]]);
            }

            $locked->update(['work_order_id' => $workOrderId, 'asset_id' => $locked->asset_id ?? $workOrder->asset_id]);
            $this->ledger->record('FACILITY_REQUEST_WORK_ORDER_LINKED', $locked, ['work_order_id' => $workOrderId]);

            return $locked->refresh();
        });
    }

    /**
     * Raises a corrective maintenance work order for the asset of the request and links it. The EAM work order is the
     * single record of the maintenance job: nothing of it is copied here.
     */
    public function raiseWorkOrder(FacilityServiceRequest $request, ?string $assetId, ?string $actorId): FacilityServiceRequest
    {
        return DB::transaction(function () use ($request, $assetId, $actorId): FacilityServiceRequest {
            $locked = $this->lock($request);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['status' => [__('facility_errors.request.locked', ['status' => $locked->status])]]);
            }

            if ($locked->work_order_id !== null) {
                throw ValidationException::withMessages(['work_order_id' => [__('facility_errors.request.work_order_exists')]]);
            }

            $assetId ??= $locked->asset_id;
            if ($assetId === null) {
                throw ValidationException::withMessages(['asset_id' => [__('facility_errors.request.asset_required')]]);
            }

            $assetExists = DB::table('eam_fixed_assets')->where('tenant_id', $locked->tenant_id)->where('id', $assetId)->whereNull('deleted_at')->exists();
            if (! $assetExists) {
                throw ValidationException::withMessages(['asset_id' => [__('facility_errors.request.asset_not_found')]]);
            }

            $workOrder = $this->maintenance->createWorkOrder(new CreateMaintenanceWorkOrderDTO(
                tenantId: (string) $locked->tenant_id,
                assetId: $assetId,
                maintenanceType: $locked->priority === 'urgent' ? 'emergency' : 'corrective',
                priority: ['urgent' => 'critical', 'high' => 'high', 'normal' => 'medium', 'low' => 'low'][$locked->priority],
                scheduledDate: CarbonImmutable::now()->toDateString(),
                problemDescription: trim($locked->number.' '.$locked->title.($locked->description !== null ? ': '.$locked->description : '')),
            ), $actorId);

            $locked->update(['work_order_id' => $workOrder->id, 'asset_id' => $assetId]);
            $this->ledger->record('FACILITY_REQUEST_WORK_ORDER_RAISED', $locked, ['work_order_id' => $workOrder->id]);

            return $locked->refresh();
        });
    }

    public function comment(FacilityServiceRequest $request, ?string $authorId, string $body, bool $internal): FacilityRequestComment
    {
        return FacilityRequestComment::create([
            'service_request_id' => $request->id,
            'author_id' => $authorId,
            'body' => $body,
            'is_internal' => $internal,
        ]);
    }

    /**
     * Open requests by status and priority, and how many are past their SLA.
     *
     * @return array{by_status: array<string, int>, by_priority: array<string, int>, overdue: int, open: int}
     */
    public function board(?string $requesterId = null): array
    {
        $query = FacilityServiceRequest::query()->when($requesterId !== null, static fn ($builder) => $builder->where('requester_id', $requesterId));

        $byStatus = array_fill_keys(FacilityServiceRequest::Statuses, 0);
        foreach ((clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') as $status => $total) {
            $byStatus[(string) $status] = (int) $total;
        }

        $byPriority = array_fill_keys(FacilityServiceRequest::Priorities, 0);
        foreach ((clone $query)->whereIn('status', FacilityServiceRequest::OpenStatuses)->selectRaw('priority, count(*) as total')->groupBy('priority')->pluck('total', 'priority') as $priority => $total) {
            $byPriority[(string) $priority] = (int) $total;
        }

        return [
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'overdue' => (clone $query)->whereIn('status', FacilityServiceRequest::OpenStatuses)->where('due_at', '<', now())->count(),
            'open' => array_sum(array_intersect_key($byStatus, array_flip(FacilityServiceRequest::OpenStatuses))),
        ];
    }

    public function dueAt(CarbonImmutable $from, string $priority): CarbonImmutable
    {
        return $from->addHours((int) (config('facility.sla_hours')[$priority] ?? 24));
    }

    /**
     * @param  callable(FacilityServiceRequest): array<string, mixed>  $extra
     */
    private function transition(FacilityServiceRequest $request, string $to, string $event, callable $extra): FacilityServiceRequest
    {
        return DB::transaction(function () use ($request, $to, $event, $extra): FacilityServiceRequest {
            $locked = $this->lock($request);

            if (! $locked->canMoveTo($to)) {
                throw ValidationException::withMessages(['status' => [__('facility_errors.request.transition', ['from' => $locked->status, 'to' => $to])]]);
            }

            $locked->update(['status' => $to, ...$extra($locked)]);
            $this->ledger->record($event, $locked, ['to' => $to]);

            return $locked->refresh();
        });
    }

    private function lock(FacilityServiceRequest $request): FacilityServiceRequest
    {
        return FacilityServiceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    private function assertOpen(FacilityServiceRequest $request): void
    {
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['status' => [__('facility_errors.request.locked', ['status' => $request->status])]]);
        }
    }

    /**
     * A request on a space carries the building of the space; a building that contradicts the space is refused.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withLocation(array $data): array
    {
        if (empty($data['space_id'])) {
            return $data;
        }

        $space = FacilitySpace::query()->find($data['space_id']);

        if ($space === null) {
            throw ValidationException::withMessages(['space_id' => [__('facility_errors.request.space_not_found')]]);
        }

        if (! empty($data['building_id']) && (string) $data['building_id'] !== (string) $space->building_id) {
            throw ValidationException::withMessages(['building_id' => [__('facility_errors.request.building_mismatch')]]);
        }

        $data['building_id'] = $space->building_id;

        return $data;
    }
}

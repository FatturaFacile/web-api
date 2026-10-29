<?php

declare(strict_types=1);

namespace Modules\HumanResources\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\AssetManagement\Events\AssetAssignedEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetTransferredEvent;
use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\Models\Employee;

final readonly class AssetAssignmentHRListener
{
    public function __construct(
        private EmployeeLifecycleServiceInterface $lifecycleService
    ) {}

    public function handleAssetAssigned(AssetAssignedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAssignmentHRListener::handleAssetAssigned')) {
            return;
        }

        if ($event->assigneeId === null) {
            return;
        }

        $employee = Employee::where('tenant_id', $event->tenantId)
            ->where(function ($q) use ($event): void {
                $q->where('id', $event->assigneeId)
                    ->orWhere('user_id', $event->assigneeId);
            })
            ->first();

        if ($employee !== null) {
            $this->lifecycleService->recordAssetAssignment($employee, $event->assetId, 'assigned', [
                'assignment_id' => $event->assignmentId,
                'assigned_at' => $event->assignedAt,
            ]);
        }
    }

    public function handleAssetTransferred(AssetTransferredEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAssignmentHRListener::handleAssetTransferred')) {
            return;
        }

        if ($event->fromCustodianId !== null) {
            $prevEmp = Employee::where('tenant_id', $event->tenantId)
                ->where(function ($q) use ($event): void {
                    $q->where('id', $event->fromCustodianId)
                        ->orWhere('user_id', $event->fromCustodianId);
                })
                ->first();

            if ($prevEmp !== null) {
                $this->lifecycleService->recordAssetAssignment($prevEmp, $event->assetId, 'transferred_out', [
                    'transfer_id' => $event->transferId,
                    'transfer_date' => $event->transferDate,
                ]);
            }
        }

        if ($event->toCustodianId !== null) {
            $newEmp = Employee::where('tenant_id', $event->tenantId)
                ->where(function ($q) use ($event): void {
                    $q->where('id', $event->toCustodianId)
                        ->orWhere('user_id', $event->toCustodianId);
                })
                ->first();

            if ($newEmp !== null) {
                $this->lifecycleService->recordAssetAssignment($newEmp, $event->assetId, 'transferred_in', [
                    'transfer_id' => $event->transferId,
                    'transfer_date' => $event->transferDate,
                ]);
            }
        }
    }

    public function handleAssetDisposed(AssetDisposedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAssignmentHRListener::handleAssetDisposed')) {
            return;
        }

        $employees = Employee::where('tenant_id', $event->tenantId)
            ->whereNotNull('equipment_assigned')
            ->get();

        foreach ($employees as $emp) {
            $eq = is_array($emp->equipment_assigned) ? $emp->equipment_assigned : [];
            if (isset($eq[$event->assetId])) {
                $this->lifecycleService->recordAssetAssignment($emp, $event->assetId, 'disposed', [
                    'disposal_id' => $event->disposalId,
                    'disposal_date' => $event->disposalDate,
                ]);
            }
        }
    }

    private function recordInboxIfNew(DomainEventInterface $event, string $consumerName): bool
    {
        $messageId = $event->getEventId();
        if (TransactionalInbox::where('message_id', $messageId)->exists()) {
            return false;
        }

        TransactionalInbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->getTenantId() ?: $event->getCompanyId(),
            'message_id' => $messageId,
            'event_type' => $event->getEventName().':'.$consumerName,
            'payload_json' => $event->toPayload(),
            'status' => 'completed',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        return true;
    }
}

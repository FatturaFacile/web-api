<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\HumanResources\Events\EmployeeHiredEvent;
use Modules\HumanResources\Events\EmployeeTerminatedEvent;
use Modules\HumanResources\Events\LeaveApprovedEvent;
use Modules\ServiceManagement\Models\ServiceTechnician;

final readonly class HRAvailabilityListener
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleEmployeeHired(EmployeeHiredEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'HRAvailabilityListener::handleEmployeeHired')) {
            return;
        }

        // Auto-provision technician record for field service readiness
        $tech = ServiceTechnician::firstOrCreate(
            ['tenant_id' => $event->tenantId, 'employee_id' => $event->employeeId],
            [
                'company_id' => $event->companyId,
                'name' => $event->fullName,
                'status' => 'available',
                'hourly_cost_rate' => round($event->baseAnnualSalary / 2080.0, 2),
                'hourly_billing_rate' => round(($event->baseAnnualSalary / 2080.0) * 2.5, 2),
                'is_active' => true,
            ]
        );

        $this->auditLedger->record(
            'ESM_TECHNICIAN_PROVISIONED_FROM_HR',
            $tech,
            null,
            ['employee_id' => $event->employeeId],
            'MODULE_17_ESM'
        );
    }

    public function handleEmployeeTerminated(EmployeeTerminatedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'HRAvailabilityListener::handleEmployeeTerminated')) {
            return;
        }

        $tech = ServiceTechnician::where('tenant_id', $event->tenantId)
            ->where('employee_id', $event->employeeId)
            ->first();

        if ($tech !== null) {
            $tech->update([
                'status' => 'off_duty',
                'is_active' => false,
            ]);

            $this->auditLedger->record(
                'ESM_TECHNICIAN_DEACTIVATED_ON_TERMINATION',
                $tech,
                null,
                ['employee_id' => $event->employeeId],
                'MODULE_17_ESM'
            );
        }
    }

    public function handleLeaveApproved(LeaveApprovedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'HRAvailabilityListener::handleLeaveApproved')) {
            return;
        }

        $tech = ServiceTechnician::where('tenant_id', $event->tenantId)
            ->where('employee_id', $event->employeeId)
            ->first();

        if ($tech !== null) {
            $tech->update(['status' => 'on_leave']);

            $this->auditLedger->record(
                'ESM_TECHNICIAN_STATUS_ON_LEAVE',
                $tech,
                null,
                ['leave_request_id' => $event->leaveRequestId],
                'MODULE_17_ESM'
            );
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
            'event_type' => $event->getEventName() . ':' . $consumerName,
            'payload_json' => $event->toPayload(),
            'status' => 'completed',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Modules\CRM\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Modules\CRM\Events\ContactCreatedEvent;
use Modules\CRM\Events\LeadCreatedEvent;
use Modules\CRM\Events\LeadQualifiedEvent;
use Modules\CRM\Services\AutomationService;
use Throwable;

/**
 * Enrols new and qualified leads and new contacts in the marketing automation sequences with the matching trigger. It
 * never breaks the operation that raised the event: a failure is logged.
 */
final class EnrollAutomationOnCrmEventListener
{
    public function __construct(private readonly AutomationService $automation, private readonly TenantContext $context) {}

    public function handleLeadCreated(LeadCreatedEvent $event): void
    {
        $this->enroll($event->tenantId, 'lead_created', null, $event->leadId);
    }

    public function handleLeadQualified(LeadQualifiedEvent $event): void
    {
        $this->enroll($event->tenantId, 'lead_qualified', null, $event->leadId);
    }

    public function handleContactCreated(ContactCreatedEvent $event): void
    {
        $this->enroll($event->tenantId, 'contact_created', $event->contactId, null);
    }

    private function enroll(string $tenantId, string $trigger, ?string $contactId, ?string $leadId): void
    {
        try {
            $tenant = Tenant::query()->find($tenantId);

            if ($tenant === null) {
                return;
            }

            $this->context->runWithTenant($tenant, fn (): int => $this->automation->enrollFromTrigger($tenantId, $trigger, $contactId, $leadId));
        } catch (Throwable $exception) {
            Log::warning('CRM automation enrolment failed', ['trigger' => $trigger, 'error' => $exception->getMessage()]);
        }
    }
}

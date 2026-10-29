<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\Contact;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\CRM\Contracts\LeadServiceInterface;
use Modules\CRM\DTOs\ConvertLeadDTO;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\Events\LeadConvertedEvent;
use Modules\CRM\Events\LeadCreatedEvent;
use Modules\CRM\Events\LeadQualifiedEvent;
use Modules\CRM\Events\LeadRejectedEvent;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\ContactRole;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadSource;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;

final readonly class LeadService implements LeadServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function captureLead(CreateLeadDTO $dto, ?string $actorUserId = null): Lead
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : (string) Str::uuid7();

            $sourceId = null;
            if ($dto->leadSourceCode) {
                $source = LeadSource::where('tenant_id', $dto->tenantId)
                    ->where('code', $dto->leadSourceCode)
                    ->first();
                $sourceId = $source?->id;
            }

            $duplicateHash = $this->generateDuplicateHash($dto->email, $dto->phone, $dto->companyName);

            $lead = Lead::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'lead_source_id' => $sourceId,
                'campaign_id' => $dto->campaignId,
                'assigned_user_id' => $dto->assignedUserId ?? $actorUuid,
                'first_name' => $dto->firstName,
                'last_name' => $dto->lastName,
                'company_name' => $dto->companyName,
                'title' => $dto->title,
                'email' => $dto->email ? strtolower(trim($dto->email)) : null,
                'phone' => $dto->phone,
                'website' => $dto->website,
                'country' => $dto->country,
                'status' => 'new',
                'estimated_budget' => $dto->estimatedBudget,
                'tags' => $dto->tags,
                'custom_fields' => $dto->customFields,
                'duplicate_hash' => $duplicateHash,
            ]);

            // Calculate and assign initial lead score
            $score = $this->scoreLead($lead);
            $rating = $score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold');

            $lead->update([
                'score' => $score,
                'rating' => $rating,
            ]);

            // Audit Trail
            $this->auditLedger->record(
                action: 'LEAD_CREATED',
                model: $lead,
                payloadBefore: null,
                payloadAfter: $lead->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            // Outbox Domain Event
            $event = new LeadCreatedEvent(
                leadId: (string) $lead->id,
                fullName: $lead->full_name,
                companyName: $lead->company_name,
                email: $lead->email,
                score: $score,
                status: (string) $lead->status,
                tenantId: $dto->tenantId,
                companyId: $dto->companyId ?? $dto->tenantId
            );
            $this->dispatcher->publish($event);
            event($event);

            // Auto-qualify high scoring leads (score >= 80)
            if ($score >= 80) {
                $this->qualifyLead($lead, $actorUuid);
            }

            return $lead->fresh();
        });
    }

    public function scoreLead(Lead $lead): int
    {
        $score = 0;

        // 1. Title / Seniority check (+20)
        $title = strtolower((string) $lead->title);
        if (preg_match('/(director|vp|c-level|ceo|cfo|cto|coo|head|chief|president|founder|owner|partner|manager)/', $title)) {
            $score += 20;
        }

        // 2. Business Email check (+15)
        $email = strtolower((string) $lead->email);
        if (! empty($email)) {
            $freeProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'libero.it', 'icloud.com'];
            $domain = substr(strrchr($email, '@') ?: '', 1);
            if (! in_array($domain, $freeProviders, true) && ! empty($domain)) {
                $score += 15;
            } else {
                $score += 5;
            }
        }

        // 3. Phone presence (+10)
        if (! empty($lead->phone)) {
            $score += 10;
        }

        // 4. Company presence (+15)
        if (! empty($lead->company_name)) {
            $score += 15;
        }

        // 5. Budget capability (+20 / +30)
        $budget = (float) $lead->estimated_budget;
        if ($budget >= 50000.0) {
            $score += 30;
        } elseif ($budget >= 10000.0) {
            $score += 20;
        } elseif ($budget > 0.0) {
            $score += 10;
        }

        // 6. Website presence (+10)
        if (! empty($lead->website)) {
            $score += 10;
        }

        return min(100, $score);
    }

    public function qualifyLead(Lead $lead, string $actorUserId): Lead
    {
        return DB::transaction(function () use ($lead, $actorUserId) {
            $lockedLead = Lead::where('id', $lead->id)->lockForUpdate()->firstOrFail();

            if ($lockedLead->status === 'qualified' || $lockedLead->status === 'converted') {
                return $lockedLead;
            }

            $before = $lockedLead->toArray();
            $lockedLead->update([
                'status' => 'qualified',
            ]);

            $this->auditLedger->record(
                action: 'LEAD_QUALIFIED',
                model: $lockedLead,
                payloadBefore: $before,
                payloadAfter: $lockedLead->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            $event = new LeadQualifiedEvent(
                leadId: (string) $lockedLead->id,
                fullName: $lockedLead->full_name,
                companyName: $lockedLead->company_name,
                score: (int) $lockedLead->score,
                estimatedBudget: $lockedLead->estimated_budget ? (float) $lockedLead->estimated_budget : null,
                actorUserId: $actorUserId,
                tenantId: (string) $lockedLead->tenant_id,
                companyId: (string) $lockedLead->tenant_id
            );
            $this->dispatcher->publish($event);
            event($event);

            return $lockedLead;
        });
    }

    public function disqualifyLead(Lead $lead, string $reason, string $actorUserId): Lead
    {
        return DB::transaction(function () use ($lead, $reason, $actorUserId) {
            $lockedLead = Lead::where('id', $lead->id)->lockForUpdate()->firstOrFail();

            $before = $lockedLead->toArray();
            $lockedLead->update([
                'status' => 'disqualified',
                'disqualification_reason' => $reason,
            ]);

            $this->auditLedger->record(
                action: 'LEAD_DISQUALIFIED',
                model: $lockedLead,
                payloadBefore: $before,
                payloadAfter: $lockedLead->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            $event = new LeadRejectedEvent(
                leadId: (string) $lockedLead->id,
                reason: $reason,
                actorUserId: $actorUserId,
                tenantId: (string) $lockedLead->tenant_id,
                companyId: (string) $lockedLead->tenant_id
            );
            $this->dispatcher->publish($event);
            event($event);

            return $lockedLead;
        });
    }

    public function convertLead(Lead $lead, ConvertLeadDTO $dto, string $actorUserId): array
    {
        return DB::transaction(function () use ($lead, $dto, $actorUserId) {
            $lockedLead = Lead::where('id', $lead->id)->lockForUpdate()->firstOrFail();

            if ($lockedLead->status === 'converted') {
                throw new InvalidArgumentException("Lead [{$lead->id}] has already been converted.");
            }

            $tenantId = (string) $lockedLead->tenant_id;
            $ownerId = $dto->ownerUserId ?? $lockedLead->assigned_user_id ?? $actorUserId;

            // 1. Resolve or Create Account
            if (! empty($dto->existingAccountId)) {
                $account = Account::where('tenant_id', $tenantId)->findOrFail($dto->existingAccountId);
            } else {
                $accountName = $dto->newAccountName ?: ($lockedLead->company_name ?: $lockedLead->full_name);
                $account = Account::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'name' => $accountName,
                    'account_number' => 'ACC-'.strtoupper(Str::random(6)),
                    'tier' => $dto->accountTier ?? 'standard',
                    'status' => 'active',
                    'annual_revenue' => $lockedLead->estimated_budget,
                    'website' => $lockedLead->website,
                    'owner_user_id' => $ownerId,
                    'customer_health_index' => 100.00,
                    'churn_risk' => 'low',
                    'lifecycle_stage' => 'prospect',
                ]);
            }

            // 2. Create or Link Contact
            $contact = Contact::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'type' => 'client',
                'country' => $lockedLead->country ?: 'IT',
                'company_name' => $account->name,
                'first_name' => $lockedLead->first_name,
                'last_name' => $lockedLead->last_name,
                'email' => $lockedLead->email,
                'phone' => $lockedLead->phone,
            ]);

            // 3. Link Contact to Account
            ContactRole::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'account_id' => $account->id,
                'contact_id' => $contact->id,
                'role' => $dto->contactRole ?? 'decision_maker',
                'is_primary' => true,
            ]);

            // Update primary contact on account if null
            if (empty($account->contact_id)) {
                $account->update(['contact_id' => $contact->id]);
            }

            // 4. Optionally Create Opportunity
            $opportunity = null;
            if ($dto->createOpportunity) {
                $stage = OpportunityStage::firstOrCreate(
                    ['tenant_id' => $tenantId, 'code' => 'discovery'],
                    [
                        'id' => (string) Str::uuid7(),
                        'name' => 'Discovery',
                        'sequence' => 10,
                        'default_probability' => 20.00,
                        'is_won' => false,
                        'is_lost' => false,
                        'is_active' => true,
                    ]
                );

                $stageId = (string) $stage->id;
                $oppName = $dto->opportunityName ?: "Deal: {$account->name}";
                $oppAmount = (float) ($dto->opportunityAmount ?? $lockedLead->estimated_budget ?? 10000.00);
                $closeDate = $dto->expectedCloseDate ?: date('Y-m-d', strtotime('+30 days'));

                $probability = $stage ? (float) $stage->default_probability : 20.00;
                $expectedRevenue = round(($oppAmount * $probability) / 100.0, 2);

                $opportunity = Opportunity::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'account_id' => $account->id,
                    'contact_id' => $contact->id,
                    'lead_id' => $lockedLead->id,
                    'stage_id' => $stageId,
                    'owner_user_id' => $ownerId,
                    'opportunity_number' => 'OPP-'.date('Ymd').'-'.strtoupper(Str::random(4)),
                    'name' => $oppName,
                    'amount' => $oppAmount,
                    'expected_revenue' => $expectedRevenue,
                    'currency' => 'EUR',
                    'probability' => $probability,
                    'forecast_category' => 'pipeline',
                    'expected_close_date' => $closeDate,
                    'status' => 'open',
                ]);
            }

            // 5. Update Lead Status
            $before = $lockedLead->toArray();
            $lockedLead->update([
                'status' => 'converted',
                'converted_at' => now(),
                'converted_account_id' => $account->id,
                'converted_contact_id' => $contact->id,
                'converted_opportunity_id' => $opportunity?->id,
            ]);

            // 6. Audit Trail
            $this->auditLedger->record(
                action: 'LEAD_CONVERTED',
                model: $lockedLead,
                payloadBefore: $before,
                payloadAfter: [
                    'lead_id' => (string) $lockedLead->id,
                    'account_id' => (string) $account->id,
                    'contact_id' => (string) $contact->id,
                    'opportunity_id' => (string) $opportunity?->id,
                ],
                moduleCode: 'MODULE_02_CRM'
            );

            // 7. Outbox Domain Event
            $event = new LeadConvertedEvent(
                leadId: (string) $lockedLead->id,
                accountId: (string) $account->id,
                contactId: (string) $contact->id,
                opportunityId: $opportunity ? (string) $opportunity->id : null,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                companyId: $tenantId
            );
            $this->dispatcher->publish($event);
            event($event);

            return [
                'account' => $account,
                'contact' => $contact,
                'opportunity' => $opportunity,
            ];
        });
    }

    public function detectDuplicates(string $tenantId, ?string $email, ?string $phone, ?string $companyName): Collection
    {
        $query = Lead::where('tenant_id', $tenantId);

        $conditions = false;
        if (! empty($email)) {
            $query->where('email', strtolower(trim($email)));
            $conditions = true;
        }

        if (! empty($phone)) {
            if ($conditions) {
                $query->orWhere('phone', $phone);
            } else {
                $query->where('phone', $phone);
                $conditions = true;
            }
        }

        if (! empty($companyName)) {
            if ($conditions) {
                $query->orWhere('company_name', 'ilike', "%{$companyName}%");
            } else {
                $query->where('company_name', 'ilike', "%{$companyName}%");
                $conditions = true;
            }
        }

        return $conditions ? $query->get() : collect();
    }

    public function assignLead(Lead $lead, string $assignedUserId, string $actorUserId): Lead
    {
        return DB::transaction(function () use ($lead, $assignedUserId) {
            $lockedLead = Lead::where('id', $lead->id)->lockForUpdate()->firstOrFail();

            $before = $lockedLead->toArray();
            $lockedLead->update(['assigned_user_id' => $assignedUserId]);

            $this->auditLedger->record(
                action: 'LEAD_ASSIGNED',
                model: $lockedLead,
                payloadBefore: $before,
                payloadAfter: $lockedLead->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            return $lockedLead;
        });
    }

    private function generateDuplicateHash(?string $email, ?string $phone, ?string $companyName): string
    {
        $cleanEmail = strtolower(trim($email ?? ''));
        $cleanPhone = preg_replace('/\D/', '', $phone ?? '');
        $cleanCompany = strtolower(trim($companyName ?? ''));

        return hash('sha256', "{$cleanEmail}|{$cleanPhone}|{$cleanCompany}");
    }
}

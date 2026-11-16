<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Enums\SupplierQualificationStatus;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Services\SupplierQualificationService;
use Modules\SupplierPortal\Enums\OnboardingStatus;
use Modules\SupplierPortal\Enums\RiskLevel;
use Modules\SupplierPortal\Models\QualificationAssessment;
use Modules\SupplierPortal\Models\SupplierOnboarding;

/**
 * The supplier lifecycle: invited, registration submitted, documents required, under review, approved, rejected or
 * blocked, with validated transitions (OnboardingStatus). Approval is gated: every mandatory document of the country
 * accepted and unexpired, a completed qualification that passed, no failed check and no critical risk. It then
 * qualifies the supplier in the procurement module, which is what lets purchase orders be issued to it.
 */
final readonly class SupplierOnboardingService
{
    public function __construct(
        private SupplierPortalAccessService $access,
        private SupplierDocumentService $documents,
        private SupplierQualificationScoringService $scoring,
        private SupplierRiskService $risk,
        private SupplierQualificationService $procurementQualification,
        private SupplierPortalLedger $ledger,
    ) {}

    /**
     * Invites an existing supplier of the procurement module and mails its personal portal link.
     *
     * @throws BusinessRuleException
     */
    public function invite(string $tenantId, Supplier $supplier, ?string $email, string $country, ?string $userId): SupplierOnboarding
    {
        if (SupplierOnboarding::query()->where('supplier_id', $supplier->id)->exists()) {
            throw new BusinessRuleException('This supplier already has an onboarding.');
        }

        $onboarding = SupplierOnboarding::create([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplier->id,
            'status' => OnboardingStatus::Invited->value,
            'country' => strtoupper($country),
            'invitation_email' => $email,
            'invited_at' => now(),
            'invited_by' => $userId,
        ]);

        $this->access->issueAccess($onboarding, $email);

        if ((string) $supplier->qualification_status === SupplierQualificationStatus::Prospect->value) {
            $supplier->update(['qualification_status' => SupplierQualificationStatus::InQualification->value]);
        }

        $this->ledger->record('SUPPLIER_ONBOARDING_INVITED', $onboarding, ['supplier_id' => $supplier->id, 'country' => $onboarding->country]);

        return $onboarding;
    }

    /**
     * Stores the registration data the supplier sent from the portal.
     *
     * @param  array<string, mixed>  $registration
     *
     * @throws BusinessRuleException
     */
    public function submitRegistration(SupplierOnboarding $onboarding, array $registration): SupplierOnboarding
    {
        return DB::transaction(function () use ($onboarding, $registration): SupplierOnboarding {
            $locked = $this->lock($onboarding);

            if (! $locked->statusEnum()->acceptsSupplierInput()) {
                throw new BusinessRuleException('The registration can no longer be changed in status '.$locked->status.'.');
            }

            $locked->update(['registration' => $registration, 'registration_submitted_at' => now(), 'country' => strtoupper((string) ($registration['country'] ?? $locked->country))]);

            if ($locked->statusEnum() === OnboardingStatus::Invited) {
                $this->transition($locked, OnboardingStatus::RegistrationSubmitted, 'SUPPLIER_REGISTRATION_SUBMITTED');
            }

            return $locked->refresh();
        });
    }

    /**
     * Reviewers ask the supplier for (more) documents.
     */
    public function requestDocuments(SupplierOnboarding $onboarding, ?string $notes): SupplierOnboarding
    {
        return $this->move($onboarding, OnboardingStatus::DocumentsRequired, 'SUPPLIER_DOCUMENTS_REQUESTED', ['status_reason' => $notes]);
    }

    /**
     * The supplier says it sent everything: all mandatory documents must have been sent; the onboarding goes to review.
     *
     * @throws BusinessRuleException
     */
    public function submitForReview(SupplierOnboarding $onboarding): SupplierOnboarding
    {
        $pending = $this->documents->notYetSent((string) $onboarding->supplier_id, (string) $onboarding->country);

        if ($pending->isNotEmpty()) {
            throw new BusinessRuleException('Send every mandatory document first: missing '.$pending->pluck('code')->implode(', ').'.');
        }

        return $this->move($onboarding, OnboardingStatus::UnderReview, 'SUPPLIER_SUBMITTED_FOR_REVIEW', ['documents_submitted_at' => now(), 'review_started_at' => now()]);
    }

    /**
     * A reviewer starts (or restarts, after a rejection or a block) the review.
     */
    public function startReview(SupplierOnboarding $onboarding): SupplierOnboarding
    {
        $reviewed = $this->move($onboarding, OnboardingStatus::UnderReview, 'SUPPLIER_REVIEW_STARTED', ['review_started_at' => now(), 'status_reason' => null]);
        Supplier::query()->whereKey($reviewed->supplier_id)->update(['qualification_status' => SupplierQualificationStatus::InQualification->value]);

        return $reviewed;
    }

    /**
     * What stops the approval of the supplier: an empty list means it can be approved.
     *
     * @return list<array{code: string, detail: ?string}>
     */
    public function approvalBlockers(SupplierOnboarding $onboarding): array
    {
        $supplier = Supplier::query()->findOrFail($onboarding->supplier_id);
        $blockers = [];

        foreach ($this->documents->missingMandatory((string) $supplier->id, (string) $onboarding->country) as $requirement) {
            $blockers[] = ['code' => 'document_not_accepted', 'detail' => $requirement->code];
        }

        $assessment = $this->scoring->latestCompleted((string) $supplier->id);

        if ($assessment === null) {
            $blockers[] = ['code' => 'qualification_missing', 'detail' => null];
        } elseif ($assessment->outcome === QualificationAssessment::NotQualified) {
            $blockers[] = ['code' => 'qualification_not_passed', 'detail' => (string) $assessment->total_score];
        }

        foreach ($this->risk->latestChecks((string) $supplier->id) as $type => $check) {
            if ($check->result === 'failed') {
                $blockers[] = ['code' => 'check_failed', 'detail' => (string) $type];
            }
        }

        if (RiskLevel::from($this->risk->evaluate($supplier)->level) === RiskLevel::Critical) {
            $blockers[] = ['code' => 'risk_critical', 'detail' => null];
        }

        return $blockers;
    }

    /**
     * Approves the supplier and qualifies it in the procurement module (conditionally when the assessment is band B).
     *
     * @throws BusinessRuleException
     */
    public function approve(SupplierOnboarding $onboarding, ?string $notes, ?string $userId): SupplierOnboarding
    {
        return DB::transaction(function () use ($onboarding, $notes, $userId): SupplierOnboarding {
            $locked = $this->lock($onboarding);

            if (! $locked->statusEnum()->canTransitionTo(OnboardingStatus::Approved)) {
                throw new BusinessRuleException("An onboarding in status {$locked->status} cannot be approved.");
            }

            $blockers = $this->approvalBlockers($locked);

            if ($blockers !== []) {
                throw new BusinessRuleException('The supplier cannot be approved yet: '.collect($blockers)->map(static fn (array $blocker): string => $blocker['code'].($blocker['detail'] !== null ? " ({$blocker['detail']})" : ''))->implode(', ').'.');
            }

            $supplier = Supplier::query()->findOrFail($locked->supplier_id);
            $assessment = $this->scoring->latestCompleted((string) $supplier->id);
            $status = $assessment?->outcome === QualificationAssessment::Conditional ? SupplierQualificationStatus::Conditional : SupplierQualificationStatus::Qualified;

            $this->procurementQualification->setStatus($supplier, $status, now()->addYear()->toDateString(), $notes);
            $supplier->update(['is_approved_vendor' => true]);

            $this->transition($locked, OnboardingStatus::Approved, 'SUPPLIER_ONBOARDING_APPROVED', ['decided_by' => $userId, 'decided_at' => now(), 'decision_notes' => $notes, 'status_reason' => null], ['qualification' => $status->value]);

            return $locked->refresh();
        });
    }

    /**
     * Rejects the onboarding; the supplier is disqualified in the procurement module.
     */
    public function reject(SupplierOnboarding $onboarding, string $reason, ?string $userId): SupplierOnboarding
    {
        return DB::transaction(function () use ($onboarding, $reason, $userId): SupplierOnboarding {
            $locked = $this->move($onboarding, OnboardingStatus::Rejected, 'SUPPLIER_ONBOARDING_REJECTED', ['decided_by' => $userId, 'decided_at' => now(), 'status_reason' => $reason]);
            $this->procurementQualification->setStatus(Supplier::query()->findOrFail($locked->supplier_id), SupplierQualificationStatus::Disqualified, null, $reason);

            return $locked;
        });
    }

    /**
     * Blocks an approved supplier: it is disqualified and loses the approved vendor flag, so no order can be issued.
     */
    public function block(SupplierOnboarding $onboarding, string $reason, ?string $userId): SupplierOnboarding
    {
        return DB::transaction(function () use ($onboarding, $reason, $userId): SupplierOnboarding {
            $locked = $this->move($onboarding, OnboardingStatus::Blocked, 'SUPPLIER_ONBOARDING_BLOCKED', ['decided_by' => $userId, 'decided_at' => now(), 'status_reason' => $reason]);
            $supplier = Supplier::query()->findOrFail($locked->supplier_id);
            $this->procurementQualification->setStatus($supplier, SupplierQualificationStatus::Disqualified, null, $reason);
            $supplier->update(['is_approved_vendor' => false]);
            $this->risk->evaluate($supplier);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function move(SupplierOnboarding $onboarding, OnboardingStatus $target, string $action, array $attributes = []): SupplierOnboarding
    {
        return DB::transaction(function () use ($onboarding, $target, $action, $attributes): SupplierOnboarding {
            $locked = $this->lock($onboarding);

            if (! $locked->statusEnum()->canTransitionTo($target)) {
                throw new BusinessRuleException("An onboarding in status {$locked->status} cannot move to {$target->value}.");
            }

            $this->transition($locked, $target, $action, $attributes);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $details
     */
    private function transition(SupplierOnboarding $locked, OnboardingStatus $target, string $action, array $attributes = [], array $details = []): void
    {
        $from = (string) $locked->status;
        $locked->update([...$attributes, 'status' => $target->value]);
        $this->ledger->record($action, $locked, ['supplier_id' => $locked->supplier_id, 'from' => $from, 'to' => $target->value, ...$details]);
    }

    private function lock(SupplierOnboarding $onboarding): SupplierOnboarding
    {
        return SupplierOnboarding::query()->whereKey($onboarding->id)->lockForUpdate()->firstOrFail();
    }
}

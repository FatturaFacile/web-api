<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use Illuminate\Support\Collection;
use Modules\Procurement\Models\Supplier;
use Modules\SupplierPortal\Enums\OnboardingStatus;
use Modules\SupplierPortal\Enums\RiskLevel;
use Modules\SupplierPortal\Models\DocumentRequirement;
use Modules\SupplierPortal\Models\SupplierCheck;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\SupplierPortal\Models\SupplierOnboarding;
use Modules\SupplierPortal\Models\SupplierRiskProfile;

/**
 * The risk flag of a supplier. Points add up from objective facts: expired or soon-expiring documents, missing
 * mandatory documents of an approved supplier, a blocked or invalid VAT position, failed or outdated checks, a blocked
 * onboarding. The level follows the total (see RiskLevel). The flag informs the buyers; it never changes an order.
 */
final readonly class SupplierRiskService
{
    public function __construct(private SupplierPortalLedger $ledger) {}

    /**
     * Recalculates and stores the risk profile of a supplier.
     */
    public function evaluate(Supplier $supplier): SupplierRiskProfile
    {
        $reasons = $this->reasonsFor($supplier);
        $score = (int) array_sum(array_column($reasons, 'points'));
        $level = RiskLevel::fromScore($score);

        $profile = SupplierRiskProfile::query()->firstOrNew(['tenant_id' => $supplier->tenant_id, 'supplier_id' => $supplier->id]);
        $previous = $profile->exists ? (string) $profile->level : RiskLevel::None->value;

        $profile->fill(['level' => $level->value, 'score' => $score, 'reasons' => $reasons, 'evaluated_at' => now()])->save();

        if ($previous !== $level->value) {
            $this->ledger->record('SUPPLIER_RISK_LEVEL_CHANGED', $profile, ['supplier_id' => $supplier->id, 'from' => $previous, 'to' => $level->value, 'score' => $score]);
        }

        return $profile;
    }

    /**
     * Recalculates every supplier that has an onboarding, a document or a check; returns how many were evaluated.
     */
    public function evaluateAll(): int
    {
        $ids = collect([
            SupplierOnboarding::query()->pluck('supplier_id'),
            SupplierDocumentSubmission::query()->distinct()->pluck('supplier_id'),
            SupplierCheck::query()->distinct()->pluck('supplier_id'),
        ])->flatten()->unique()->values();

        $count = 0;

        foreach (Supplier::query()->whereIn('id', $ids)->get() as $supplier) {
            $this->evaluate($supplier);
            $count++;
        }

        return $count;
    }

    /**
     * The latest check of every type of the supplier.
     *
     * @return Collection<string, SupplierCheck>
     */
    public function latestChecks(string $supplierId): Collection
    {
        return SupplierCheck::query()
            ->where('supplier_id', $supplierId)
            ->orderBy('created_at')
            ->get()
            ->groupBy('check_type')
            ->map(static fn (Collection $checks): SupplierCheck => $checks->sortBy(static fn (SupplierCheck $check): string => ($check->checked_at?->toDateString() ?? '0000-00-00').$check->created_at?->format('Y-m-d H:i:s.u'))->last());
    }

    /**
     * @return list<array{code: string, points: int, detail: ?string}>
     */
    private function reasonsFor(Supplier $supplier): array
    {
        $today = now()->toDateString();
        $reasons = [];

        $accepted = SupplierDocumentSubmission::query()
            ->with('requirement')
            ->where('supplier_id', $supplier->id)
            ->where('status', SupplierDocumentSubmission::Accepted)
            ->whereNotNull('expires_at')
            ->get();

        $expired = $accepted->filter(static fn (SupplierDocumentSubmission $document): bool => $document->isExpired($today) && (bool) $document->requirement?->is_mandatory);

        foreach ($expired->take(2) as $document) {
            $reasons[] = ['code' => 'document_expired', 'points' => 40, 'detail' => $document->requirement?->code];
        }

        foreach ($accepted->reject(static fn (SupplierDocumentSubmission $document): bool => $document->isExpired($today))->filter(static fn (SupplierDocumentSubmission $document): bool => $document->expires_at->toDateString() <= now()->addDays((int) ($document->requirement?->alert_days ?? 30))->toDateString())->take(3) as $document) {
            $reasons[] = ['code' => 'document_expiring', 'points' => 10, 'detail' => $document->requirement?->code];
        }

        $onboarding = SupplierOnboarding::query()->where('supplier_id', $supplier->id)->first();

        if ($onboarding !== null && $onboarding->statusEnum() === OnboardingStatus::Approved) {
            $valid = SupplierDocumentSubmission::query()
                ->where('supplier_id', $supplier->id)
                ->where('status', SupplierDocumentSubmission::Accepted)
                ->where(static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', $today))
                ->pluck('requirement_id')
                ->all();

            $expiredRequirementIds = $expired->pluck('requirement_id')->all();

            DocumentRequirement::query()
                ->where('is_active', true)
                ->where('is_mandatory', true)
                ->where(static fn ($query) => $query->whereNull('country')->orWhere('country', $onboarding->country))
                ->get()
                ->reject(static fn (DocumentRequirement $requirement): bool => in_array($requirement->id, $valid, true) || in_array($requirement->id, $expiredRequirementIds, true))
                ->each(static function (DocumentRequirement $requirement) use (&$reasons): void {
                    $reasons[] = ['code' => 'document_missing', 'points' => 20, 'detail' => $requirement->code];
                });
        }

        if ($onboarding !== null && $onboarding->statusEnum() === OnboardingStatus::Blocked) {
            $reasons[] = ['code' => 'onboarding_blocked', 'points' => 100, 'detail' => null];
        }

        foreach ($this->latestChecks($supplier->id) as $type => $check) {
            $reasons = [...$reasons, ...$this->reasonsOfCheck((string) $type, $check, $today)];
        }

        if ((string) $supplier->qualification_status === 'expired') {
            $reasons[] = ['code' => 'qualification_expired', 'points' => 20, 'detail' => null];
        }

        return $reasons;
    }

    /**
     * @return list<array{code: string, points: int, detail: ?string}>
     */
    private function reasonsOfCheck(string $type, SupplierCheck $check, string $today): array
    {
        if ($check->result === 'failed') {
            return [match ($type) {
                'vat_status' => ['code' => 'vat_blocked', 'points' => 60, 'detail' => $type],
                'vies_vat' => ['code' => 'vat_invalid', 'points' => 40, 'detail' => $type],
                'sanctions' => ['code' => 'sanctions_hit', 'points' => 100, 'detail' => $type],
                default => ['code' => 'check_failed', 'points' => 50, 'detail' => $type],
            }];
        }

        if ($check->result === 'pending') {
            return [['code' => 'check_pending', 'points' => 5, 'detail' => $type]];
        }

        if ($check->valid_until !== null && $check->valid_until->toDateString() < $today) {
            return [['code' => 'check_outdated', 'points' => 10, 'detail' => $type]];
        }

        return [];
    }
}

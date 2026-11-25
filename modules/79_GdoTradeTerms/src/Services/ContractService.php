<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Domain\Enums\ContractStatus;
use Modules\GdoTradeTerms\Domain\Enums\Jurisdiction;
use Modules\GdoTradeTerms\Domain\Enums\LineStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Exceptions\GdoTradeTermsException;
use Modules\GdoTradeTerms\Models\GdoAccrualLine;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Support\NotNull;

/**
 * Framework contracts: creation, the contract window against the windows of its scales and promotions, and the lifecycle
 * draft -> active <-> suspended -> closed (or cancelled while nothing was accrued).
 */
final class ContractService
{
    /** @var list<string> */
    private const array Editable = [
        'code', 'name', 'contact_id', 'include_child_contacts', 'jurisdiction', 'currency', 'starts_on', 'ends_on',
        'discount_1_percent', 'discount_2_percent', 'discount_3_percent', 'cedi_discount_percent', 'pallet_discount_amount',
        'tolerance_percent', 'expense_account_id', 'provision_account_id', 'payable_account_id', 'vat_account_id', 'notes',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $tenantId, ?string $userId): GdoContract
    {
        $contact = Contact::query()->find($data['contact_id'] ?? null);

        if ($contact === null) {
            throw new GdoTradeTermsException('contact_not_found');
        }

        $this->assertPeriod((string) $data['starts_on'], (string) $data['ends_on']);
        $country = strtoupper((string) $contact->country);
        $jurisdiction = (string) ($data['jurisdiction'] ?? ($country === 'CH' ? Jurisdiction::Ch->value : Jurisdiction::It->value));

        return GdoContract::create([
            ...$this->attributes($data),
            'tenant_id' => $tenantId,
            'jurisdiction' => $jurisdiction,
            'currency' => strtoupper((string) ($data['currency'] ?? ($jurisdiction === 'CH' ? 'CHF' : 'EUR'))),
            'status' => ContractStatus::Draft->value,
            'created_by' => $userId,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GdoContract $contract, array $data, ?string $userId): GdoContract
    {
        $this->assertEditable($contract);

        if (isset($data['contact_id']) && $data['contact_id'] !== $contract->contact_id) {
            if (Contact::query()->find($data['contact_id']) === null) {
                throw new GdoTradeTermsException('contact_not_found');
            }

            if ($this->hasAccruals($contract)) {
                throw new GdoTradeTermsException('contract_has_accruals', 409);
            }
        }

        $starts = (string) ($data['starts_on'] ?? $contract->starts_on->toDateString());
        $ends = (string) ($data['ends_on'] ?? $contract->ends_on->toDateString());
        $this->assertPeriod($starts, $ends);
        $this->assertContainsChildren($contract, Carbon::parse($starts), Carbon::parse($ends));

        $contract->update([...$this->attributes($data), 'updated_by' => $userId]);

        return $contract->refresh();
    }

    public function transition(GdoContract $contract, string $action, ?string $userId): GdoContract
    {
        $target = match ($action) {
            'activate', 'resume' => ContractStatus::Active,
            'suspend' => ContractStatus::Suspended,
            'close' => ContractStatus::Closed,
            'cancel' => ContractStatus::Cancelled,
            default => throw new GdoTradeTermsException('invalid_action'),
        };

        $allowed = match ($action) {
            'activate' => [ContractStatus::Draft],
            'resume' => [ContractStatus::Suspended],
            'suspend' => [ContractStatus::Active],
            'close' => [ContractStatus::Active, ContractStatus::Suspended],
            'cancel' => [ContractStatus::Draft, ContractStatus::Suspended],
        };

        if (! in_array($contract->status, $allowed, true)) {
            throw new GdoTradeTermsException('invalid_transition', 409, ['from' => $contract->status->value, 'to' => $target->value]);
        }

        if ($action === 'activate') {
            $hasScale = $contract->scales()->where('is_active', true)->whereHas('tiers')->exists();
            $hasPromotion = $contract->promotions()->where('status', '!=', PromotionStatus::Cancelled->value)->exists();

            if (! $hasScale && ! $hasPromotion) {
                throw new GdoTradeTermsException('nothing_to_activate');
            }
        }

        if ($action === 'cancel' && $this->hasAccruals($contract)) {
            throw new GdoTradeTermsException('contract_has_accruals', 409);
        }

        if ($action === 'close' && GdoSettlement::query()->where('contract_id', $contract->id)->whereIn('status', ['proposed', 'disputed'])->exists()) {
            throw new GdoTradeTermsException('open_settlements', 409);
        }

        $contract->update([
            'status' => $target->value,
            'activated_at' => $action === 'activate' ? now() : $contract->activated_at,
            'closed_at' => $action === 'close' ? now() : $contract->closed_at,
            'updated_by' => $userId,
        ]);

        return $contract->refresh();
    }

    public function delete(GdoContract $contract, ?string $userId): void
    {
        if ($contract->status !== ContractStatus::Draft || $this->hasAccruals($contract)) {
            throw new GdoTradeTermsException('contract_not_deletable', 409);
        }

        DB::transaction(function () use ($contract, $userId): void {
            foreach ($contract->scales as $scale) {
                $scale->tiers()->delete();
                $scale->update(['deleted_by' => $userId]);
                $scale->delete();
            }

            $contract->promotions()->delete();
            $contract->update(['deleted_by' => $userId]);
            $contract->delete();
        });
    }

    public function assertEditable(GdoContract $contract): void
    {
        if (in_array($contract->status, [ContractStatus::Closed, ContractStatus::Cancelled], true)) {
            throw new GdoTradeTermsException('contract_locked', 409);
        }
    }

    public function hasAccruals(GdoContract $contract): bool
    {
        return GdoAccrualLine::query()->where('contract_id', $contract->id)->where('status', '!=', LineStatus::Reversed->value)->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return NotNull::strip(array_intersect_key($data, array_flip(self::Editable)), [
            'include_child_contacts', 'jurisdiction', 'currency', 'discount_1_percent', 'discount_2_percent', 'discount_3_percent',
            'cedi_discount_percent', 'pallet_discount_amount', 'tolerance_percent',
        ]);
    }

    private function assertPeriod(string $start, string $end): void
    {
        if (Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw new GdoTradeTermsException('period_invalid');
        }
    }

    private function assertContainsChildren(GdoContract $contract, Carbon $start, Carbon $end): void
    {
        $outsideScale = $contract->scales()->get()->contains(fn ($scale): bool => ($scale->starts_on !== null && $scale->starts_on->lt($start)) || ($scale->ends_on !== null && $scale->ends_on->gt($end)));
        $outsidePromotion = $contract->promotions()->where('status', '!=', PromotionStatus::Cancelled->value)->get()->contains(fn ($promotion): bool => $promotion->starts_on->lt($start) || $promotion->ends_on->gt($end));

        if ($outsideScale || $outsidePromotion) {
            throw new GdoTradeTermsException('window_excludes_children');
        }
    }
}

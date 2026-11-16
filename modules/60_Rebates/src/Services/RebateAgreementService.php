<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateTier;

/**
 * Lifecycle of the rebate agreements. Draft agreements are freely editable; once active only the name, the notes and the
 * end date can change, so the accruals always follow the scale that was agreed.
 */
final class RebateAgreementService
{
    /** Fields that define the rebate: locked once the agreement is no longer a draft. */
    private const array StructuralFields = ['direction', 'contact_id', 'tier_mode', 'starts_on', 'currency', 'tiers'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): RebateAgreement
    {
        return DB::transaction(function () use ($tenantId, $data): RebateAgreement {
            $this->assertCodeFree($tenantId, (string) $data['code'], null);
            $agreement = RebateAgreement::create([...$this->fields($data), 'tenant_id' => $tenantId, 'status' => RebateAgreement::Draft]);
            $this->syncTiers($agreement, $data['tiers']);

            return $agreement->load('tiers');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RebateAgreement $agreement, array $data): RebateAgreement
    {
        return DB::transaction(function () use ($agreement, $data): RebateAgreement {
            if ($agreement->status !== RebateAgreement::Draft && array_intersect(self::StructuralFields, array_keys($data)) !== []) {
                throw ValidationException::withMessages(['agreement' => [__('rebate_errors.agreement.locked')]]);
            }

            if (isset($data['code'])) {
                $this->assertCodeFree((string) $agreement->tenant_id, (string) $data['code'], (string) $agreement->id);
            }

            $agreement->update($this->fields($data));

            if (isset($data['tiers'])) {
                $this->syncTiers($agreement, $data['tiers']);
            }

            if ($agreement->ends_on->lt($agreement->starts_on)) {
                throw ValidationException::withMessages(['ends_on' => [__('rebate_errors.agreement.period')]]);
            }

            return $agreement->refresh()->load('tiers');
        });
    }

    public function activate(RebateAgreement $agreement): RebateAgreement
    {
        if ($agreement->status !== RebateAgreement::Draft) {
            throw ValidationException::withMessages(['agreement' => [__('rebate_errors.agreement.not_draft')]]);
        }

        if (! $agreement->tiers()->exists()) {
            throw ValidationException::withMessages(['tiers' => [__('rebate_errors.agreement.tiers_required')]]);
        }

        $agreement->update(['status' => RebateAgreement::Active]);

        return $agreement->refresh()->load('tiers');
    }

    public function close(RebateAgreement $agreement): RebateAgreement
    {
        if ($agreement->status !== RebateAgreement::Active) {
            throw ValidationException::withMessages(['agreement' => [__('rebate_errors.agreement.not_active')]]);
        }

        $agreement->update(['status' => RebateAgreement::Closed]);

        return $agreement->refresh()->load('tiers');
    }

    public function delete(RebateAgreement $agreement): void
    {
        if ($agreement->status !== RebateAgreement::Draft || RebateAccrual::query()->where('agreement_id', $agreement->id)->exists()) {
            throw ValidationException::withMessages(['agreement' => [__('rebate_errors.agreement.delete_draft_only')]]);
        }

        DB::transaction(function () use ($agreement): void {
            RebateTier::query()->where('agreement_id', $agreement->id)->delete();
            $agreement->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return array_diff_key($data, ['tiers' => true]);
    }

    /**
     * @param  list<array{threshold_amount: string|int|float, rate_percent: string|int|float}>  $tiers
     */
    private function syncTiers(RebateAgreement $agreement, array $tiers): void
    {
        RebateTier::query()->where('agreement_id', $agreement->id)->delete();

        foreach ($tiers as $tier) {
            RebateTier::create([
                'tenant_id' => $agreement->tenant_id,
                'agreement_id' => $agreement->id,
                'threshold_amount' => $tier['threshold_amount'],
                'rate_percent' => $tier['rate_percent'],
            ]);
        }
    }

    private function assertCodeFree(string $tenantId, string $code, ?string $ignoreId): void
    {
        $taken = RebateAgreement::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => [__('rebate_errors.agreement.code_taken', ['code' => $code])]]);
        }
    }
}

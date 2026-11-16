<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Support\LeaseMoney;

/**
 * Life of a lease contract before and outside the measurement: drafting and changing the terms, cancelling a draft,
 * and the business rules that tie the terms together (term in whole payment periods, options, exemptions).
 */
final readonly class LeaseContractService
{
    /** Terms a new lease starts with when the request does not state them. */
    private const array Defaults = [
        'accounting_standard' => 'ifrs16',
        'payment_frequency' => 'monthly',
        'payment_timing' => 'arrears',
        'escalation_type' => 'none',
        'escalation_rate_percent' => '0',
        'initial_direct_costs' => '0',
        'incentives_received' => '0',
        'purchase_option_reasonably_certain' => false,
        'extension_reasonably_certain' => false,
        'exemption' => 'none',
    ];

    public function __construct(private LeaseNumbers $numbers) {}

    /**
     * @param  array<string, mixed>  $data  validated terms of the lease
     */
    public function create(string $tenantId, array $data, ?string $actorId): LeaseContract
    {
        $data = $this->normalised($data + self::Defaults);
        $this->assertConsistent($data);

        return DB::transaction(fn (): LeaseContract => LeaseContract::create($data + [
            'tenant_id' => $tenantId,
            'number' => $this->numbers->next($tenantId),
            'status' => 'draft',
            'current_version_number' => 0,
            'created_by' => $actorId,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data  validated terms to change
     */
    public function update(LeaseContract $contract, array $data): LeaseContract
    {
        $this->assertDraft($contract, 'only_draft_editable');
        $merged = $this->normalised($data + $this->termsOf($contract));
        $this->assertConsistent($merged);
        $contract->update($merged);

        return $contract->refresh();
    }

    public function delete(LeaseContract $contract): void
    {
        $this->assertDraft($contract, 'only_draft_deletable');
        $contract->delete();
    }

    /**
     * Cancels a draft. A lease that was activated has a measurement and postings: it is never cancelled.
     */
    public function cancel(LeaseContract $contract, string $reason): LeaseContract
    {
        return DB::transaction(function () use ($contract, $reason): LeaseContract {
            $locked = LeaseContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($locked, 'cancelled');
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $reason]);

            return $locked->refresh();
        });
    }

    /**
     * @throws ValidationException when the lease cannot move to the status
     */
    public function assertTransition(LeaseContract $contract, string $status): void
    {
        if (! $contract->canTransitionTo($status)) {
            throw ValidationException::withMessages(['status' => [__('lease_errors.contract.transition', ['status' => $contract->status, 'target' => $status])]]);
        }
    }

    /**
     * The business rules that tie the terms of a lease together.
     *
     * @param  array<string, mixed>  $terms  complete terms (the attributes of the contract)
     *
     * @throws ValidationException
     */
    public function assertConsistent(array $terms): void
    {
        $frequency = LeaseContract::FrequencyMonths[(string) $terms['payment_frequency']];
        $termMonths = (int) $terms['term_months'];
        $extensionCertain = (bool) ($terms['extension_reasonably_certain'] ?? false);
        $optionCertain = (bool) ($terms['purchase_option_reasonably_certain'] ?? false);
        $extensionMonths = (int) ($terms['extension_months'] ?? 0);

        if ($extensionCertain && $extensionMonths < 1) {
            throw ValidationException::withMessages(['extension_months' => [__('lease_errors.contract.extension_required')]]);
        }

        $effectiveTerm = $termMonths + ($extensionCertain ? $extensionMonths : 0);

        if ($effectiveTerm > (int) config('leases.max_term_months')) {
            throw ValidationException::withMessages(['term_months' => [__('lease_errors.contract.term_too_long', ['max' => config('leases.max_term_months')])]]);
        }

        if ($effectiveTerm % $frequency !== 0) {
            throw ValidationException::withMessages(['term_months' => [__('lease_errors.contract.term_multiple', ['term' => $effectiveTerm, 'frequency' => $frequency])]]);
        }

        if ($optionCertain) {
            if (($terms['purchase_option_price'] ?? null) === null || ($terms['useful_life_months'] ?? null) === null) {
                throw ValidationException::withMessages(['purchase_option_price' => [__('lease_errors.contract.purchase_option_required')]]);
            }

            if ((int) $terms['useful_life_months'] < $effectiveTerm) {
                throw ValidationException::withMessages(['useful_life_months' => [__('lease_errors.contract.useful_life_short')]]);
            }
        }

        $exemption = (string) ($terms['exemption'] ?? 'none');

        if ($exemption === 'short_term' && ($effectiveTerm > (int) config('leases.short_term_max_months') || $optionCertain)) {
            throw ValidationException::withMessages(['exemption' => [__('lease_errors.contract.short_term_limit', ['max' => config('leases.short_term_max_months')])]]);
        }

        if ($exemption === 'low_value') {
            $value = $terms['underlying_asset_value'] ?? null;

            if ($value === null) {
                throw ValidationException::withMessages(['underlying_asset_value' => [__('lease_errors.contract.low_value_required')]]);
            }

            $threshold = (string) config('leases.low_value_threshold.'.$terms['currency']);

            if (LeaseMoney::compare(LeaseMoney::normalize((string) $value), $threshold) > 0) {
                throw ValidationException::withMessages(['exemption' => [__('lease_errors.contract.low_value_limit', ['value' => $value, 'max' => $threshold, 'currency' => $terms['currency']])]]);
            }
        }
    }

    /**
     * Terms that do not apply are cleared: no escalation rate without an escalation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalised(array $data): array
    {
        if (($data['escalation_type'] ?? 'none') === 'none' || ($data['escalation_rate_percent'] ?? null) === null) {
            $data['escalation_rate_percent'] = '0';
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function termsOf(LeaseContract $contract): array
    {
        $terms = [];

        foreach (self::termFields() as $field) {
            $terms[$field] = $contract->getAttribute($field);
        }

        return $terms;
    }

    /**
     * @return list<string>
     */
    public static function termFields(): array
    {
        return [
            'title', 'lessor_name', 'lessor_contact_id', 'asset_class', 'asset_description', 'fixed_asset_id', 'accounting_standard',
            'currency', 'commencement_date', 'term_months', 'payment_amount', 'payment_frequency', 'payment_timing',
            'escalation_type', 'escalation_rate_percent', 'incremental_borrowing_rate_percent', 'initial_direct_costs',
            'incentives_received', 'purchase_option_price', 'purchase_option_reasonably_certain', 'extension_months',
            'extension_reasonably_certain', 'useful_life_months', 'exemption', 'underlying_asset_value',
            'rou_asset_account_id', 'accumulated_depreciation_account_id', 'lease_liability_account_id',
            'interest_expense_account_id', 'depreciation_expense_account_id', 'notes',
        ];
    }

    /**
     * @throws ValidationException when the lease is not a draft
     */
    private function assertDraft(LeaseContract $contract, string $key): void
    {
        if ($contract->status !== 'draft') {
            throw ValidationException::withMessages(['status' => [__("lease_errors.contract.{$key}", ['status' => $contract->status])]]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\CreditManagement\Models\CreditInsuranceCover;
use Modules\CreditManagement\Models\CreditInsurancePolicy;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Credit insurance policies and the covers granted per customer. A cover can only be added to a policy in force; the
 * covers granted under a policy stay within its total cover; a customer has one live cover (requested or granted) per
 * policy. The insured limit of a customer is the sum of its granted, unexpired covers under policies in force.
 */
final readonly class InsuranceService
{
    private const array PolicyFields = ['insurer', 'policy_number', 'starts_on', 'ends_on', 'currency', 'coverage_percentage', 'max_total_cover', 'status', 'notes'];

    private const array CoverFields = ['insured_limit', 'status', 'granted_on', 'expires_on', 'notes'];

    public function __construct(private ReceivablesLookupInterface $receivables) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPolicy(string $tenantId, array $data): CreditInsurancePolicy
    {
        $data['status'] ??= CreditInsurancePolicy::Active;
        $this->assertPolicyDates($data);

        return CreditInsurancePolicy::create([...array_intersect_key($data, array_flip(self::PolicyFields)), 'tenant_id' => $tenantId])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePolicy(CreditInsurancePolicy $policy, array $data): CreditInsurancePolicy
    {
        $merged = [...$policy->only(self::PolicyFields), ...$data];
        $merged['starts_on'] = Carbon::parse($merged['starts_on'])->toDateString();
        $merged['ends_on'] = Carbon::parse($merged['ends_on'])->toDateString();
        $this->assertPolicyDates($merged);

        return DB::transaction(function () use ($policy, $merged, $data): CreditInsurancePolicy {
            if (isset($data['max_total_cover']) && Decimal::compare($this->grantedTotal($policy), (string) $data['max_total_cover']) > 0) {
                throw ValidationException::withMessages(['max_total_cover' => [__('credit_errors.insurance.total_exceeded', ['granted' => $this->grantedTotal($policy)])]]);
            }

            $policy->update(array_intersect_key($merged, array_flip(self::PolicyFields)));

            return $policy->refresh();
        });
    }

    public function deletePolicy(CreditInsurancePolicy $policy): void
    {
        if ($policy->covers()->exists()) {
            throw ValidationException::withMessages(['policy' => [__('credit_errors.insurance.policy_has_covers')]]);
        }

        $policy->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCover(string $tenantId, CreditInsurancePolicy $policy, array $data): CreditInsuranceCover
    {
        $data['status'] ??= CreditInsuranceCover::Granted;

        return DB::transaction(function () use ($tenantId, $policy, $data): CreditInsuranceCover {
            $data['customer_id'] = $this->assertCustomer($tenantId, (string) $data['customer_id']);
            $data = $this->normalizeCover($policy, $data);
            $this->assertCover($policy, $data, null, (string) $data['customer_id']);

            return CreditInsuranceCover::create([...array_intersect_key($data, array_flip(self::CoverFields)), 'tenant_id' => $tenantId, 'policy_id' => $policy->id, 'customer_id' => $data['customer_id']])->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCover(CreditInsuranceCover $cover, array $data): CreditInsuranceCover
    {
        return DB::transaction(function () use ($cover, $data): CreditInsuranceCover {
            $policy = $cover->policy()->firstOrFail();
            $merged = $this->normalizeCover($policy, [...$cover->only(self::CoverFields), ...$data]);
            $this->assertCover($policy, $merged, $cover, (string) $cover->customer_id);
            $cover->update(array_intersect_key($merged, array_flip(self::CoverFields)));

            return $cover->refresh();
        });
    }

    /**
     * Insured limit of the customer on the given day.
     */
    public function insuredLimit(string $tenantId, string $customerId, Carbon $today): string
    {
        $total = '0';

        $covers = CreditInsuranceCover::query()
            ->with('policy')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('status', CreditInsuranceCover::Granted)
            ->get();

        foreach ($covers as $cover) {
            $expired = $cover->expires_on !== null && $cover->expires_on->lt($today->copy()->startOfDay());

            if (! $expired && $cover->policy !== null && $cover->policy->isInForceOn($today)) {
                $total = Decimal::add($total, (string) $cover->insured_limit);
            }
        }

        return Decimal::round2($total);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeCover(CreditInsurancePolicy $policy, array $data): array
    {
        if ($data['status'] === CreditInsuranceCover::Granted) {
            $data['granted_on'] = isset($data['granted_on']) ? Carbon::parse($data['granted_on'])->toDateString() : Carbon::today()->toDateString();
            $data['expires_on'] = isset($data['expires_on']) ? Carbon::parse($data['expires_on'])->toDateString() : $policy->ends_on->toDateString();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertCover(CreditInsurancePolicy $policy, array $data, ?CreditInsuranceCover $existing, string $customerId): void
    {
        $live = in_array($data['status'], [CreditInsuranceCover::Requested, CreditInsuranceCover::Granted], true);

        if ($live && ! $policy->isInForceOn(Carbon::today())) {
            throw ValidationException::withMessages(['policy_id' => [__('credit_errors.insurance.policy_not_active')]]);
        }

        if ($live && isset($data['expires_on']) && Carbon::parse($data['expires_on'])->gt($policy->ends_on)) {
            throw ValidationException::withMessages(['expires_on' => [__('credit_errors.insurance.period')]]);
        }

        if ($live && CreditInsuranceCover::query()
            ->where('policy_id', $policy->id)
            ->where('customer_id', $customerId)
            ->whereIn('status', [CreditInsuranceCover::Requested, CreditInsuranceCover::Granted])
            ->when($existing !== null, static fn ($query) => $query->whereKeyNot($existing->id))
            ->exists()) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.insurance.duplicate')]]);
        }

        if ($data['status'] === CreditInsuranceCover::Granted && $policy->max_total_cover !== null) {
            $others = $this->grantedTotal($policy, $existing?->id);

            if (Decimal::compare(Decimal::add($others, (string) $data['insured_limit']), (string) $policy->max_total_cover) > 0) {
                throw ValidationException::withMessages(['insured_limit' => [__('credit_errors.insurance.total_exceeded', ['granted' => $others])]]);
            }
        }
    }

    private function grantedTotal(CreditInsurancePolicy $policy, ?string $exceptCoverId = null): string
    {
        $total = '0';

        foreach (CreditInsuranceCover::query()->where('policy_id', $policy->id)->where('status', CreditInsuranceCover::Granted)->when($exceptCoverId !== null, static fn ($query) => $query->whereKeyNot($exceptCoverId))->get(['insured_limit']) as $cover) {
            $total = Decimal::add($total, (string) $cover->insured_limit);
        }

        return Decimal::round2($total);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertPolicyDates(array $data): void
    {
        if (Carbon::parse($data['ends_on'])->lte(Carbon::parse($data['starts_on']))) {
            throw ValidationException::withMessages(['ends_on' => [__('credit_errors.insurance.dates')]]);
        }
    }

    private function assertCustomer(string $tenantId, string $customerId): string
    {
        if ($this->receivables->customerName($tenantId, $customerId) === null) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.customer.missing')]]);
        }

        return $customerId;
    }
}

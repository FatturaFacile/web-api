<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Models\CustomerCreditLimit;
use Modules\Invoicing\Models\CustomerCreditOverride;
use Modules\Invoicing\Models\CustomerCreditProfile;

/**
 * Credit limits of the customers with their validity, and the audited overrides of a credit block. Creating a limit
 * closes the open-ended limit it replaces, and keeps the credit profile (used by the order credit check) in line with
 * the limit in force today.
 */
final class CreditLimitService
{
    public function __construct(
        private readonly CreditExposureService $exposure,
        private readonly CustomerCreditServiceInterface $legacyCredit,
        private readonly TenantClock $clock,
    ) {}

    public function today(string $tenantId): CarbonImmutable
    {
        return $this->clock->today(Tenant::query()->find($tenantId));
    }

    public function effective(string $tenantId, string $customerId, ?CarbonImmutable $date = null): ?CustomerCreditLimit
    {
        return CustomerCreditLimit::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->effectiveOn($date ?? $this->today($tenantId))
            ->orderByDesc('valid_from')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return Collection<int, CustomerCreditLimit>
     */
    public function history(string $tenantId, string $customerId): Collection
    {
        return CustomerCreditLimit::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->orderByDesc('valid_from')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the period or the insurance data are inconsistent
     */
    public function create(string $tenantId, array $data, ?string $userId): CustomerCreditLimit
    {
        $this->assertConsistent($data);

        $limit = DB::transaction(function () use ($tenantId, $data, $userId): CustomerCreditLimit {
            $from = CarbonImmutable::parse((string) $data['valid_from']);

            // The limit this one replaces stops the day before it starts.
            CustomerCreditLimit::query()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $data['customer_id'])
                ->where('is_active', true)
                ->whereDate('valid_from', '<', $from->toDateString())
                ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from->toDateString()))
                ->update(['valid_to' => $from->subDay()->toDateString()]);

            return CustomerCreditLimit::create($this->attributes($data) + [
                'tenant_id' => $tenantId,
                'created_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
            ]);
        });

        $this->syncProfile($tenantId, (string) $data['customer_id'], $userId);

        return $limit;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerCreditLimit $limit, array $data, ?string $userId): CustomerCreditLimit
    {
        $merged = $data + [
            'customer_id' => (string) $limit->customer_id,
            'granted_limit' => $limit->granted_limit,
            'insured_limit' => $limit->insured_limit,
            'coverage_percentage' => $limit->coverage_percentage,
            'valid_from' => $limit->valid_from->toDateString(),
            'valid_to' => $limit->valid_to?->toDateString(),
        ];
        $this->assertConsistent($merged);

        $limit->update($this->attributes($data));
        $this->syncProfile((string) $limit->tenant_id, (string) $limit->customer_id, $userId);

        return $limit->refresh();
    }

    public function delete(CustomerCreditLimit $limit, ?string $userId): void
    {
        $limit->delete();
        $this->syncProfile((string) $limit->tenant_id, (string) $limit->customer_id, $userId);
    }

    /**
     * Allows what the credit block of the customer stops, for a scope and until a date, on the word of an authorised user.
     */
    public function grantOverride(string $tenantId, string $customerId, string $scope, string $reason, ?string $expiresAt, string $userId): CustomerCreditOverride
    {
        if ($expiresAt !== null && CarbonImmutable::parse($expiresAt)->isPast()) {
            throw ValidationException::withMessages(['expires_at' => __('receivables.override_expiry_past')]);
        }

        return CustomerCreditOverride::create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'scope' => $scope,
            'reason' => $reason,
            'authorized_by' => $userId,
            'expires_at' => $expiresAt,
        ]);
    }

    public function revokeOverride(CustomerCreditOverride $override, string $userId, ?string $reason): CustomerCreditOverride
    {
        if ($override->revoked_at !== null) {
            throw ValidationException::withMessages(['override' => __('receivables.override_already_revoked')]);
        }

        $override->update(['revoked_at' => now(), 'revoked_by' => $userId, 'revoke_reason' => $reason]);

        return $override->refresh();
    }

    /**
     * @return Collection<int, CustomerCreditOverride>
     */
    public function activeOverrides(string $tenantId, string $customerId): Collection
    {
        return CustomerCreditOverride::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->active(now())->orderByDesc('created_at')->get();
    }

    /**
     * Credit position of a customer: the limit in force, the exposure with its components, the utilisation and what is
     * still available, the insured share and the overrides in force.
     *
     * @return array<string, mixed>
     */
    public function status(string $tenantId, string $customerId): array
    {
        $today = $this->today($tenantId);
        $limit = $this->effective($tenantId, $customerId, $today);
        $exposure = $this->exposure->calculate($tenantId, $customerId, $today);
        $granted = $limit !== null ? (string) $limit->granted_limit : '0.00';
        $hasLimit = Decimal::isPositive($granted);
        $profile = CustomerCreditProfile::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->first();

        $utilization = $hasLimit ? Decimal::round2(Decimal::mul(Decimal::div($exposure['total_exposure'], $granted), '100')) : null;
        $available = $hasLimit ? Decimal::round2(Decimal::sub($granted, $exposure['total_exposure'])) : null;
        $insuredCover = $limit?->insuredCover() ?? '0.00';
        $uninsured = Decimal::isPositive($exposure['total_exposure']) ? Decimal::sub($exposure['total_exposure'], $insuredCover) : '0';

        $decision = app(CreditEnforcementService::class)->evaluate($tenantId, $customerId, 'order', '0');

        return [
            'customer_id' => $customerId,
            'as_of' => $today->toDateString(),
            'limit' => $limit,
            'exposure' => $exposure,
            'utilization_percent' => $utilization,
            'available_credit' => $available,
            'insured_cover' => $insuredCover,
            'uninsured_exposure' => Decimal::isPositive($uninsured) ? Decimal::round2($uninsured) : '0.00',
            'is_credit_hold' => (bool) ($profile->is_credit_hold ?? false),
            'is_blocked' => $decision->blocks(),
            'decision' => $decision->toArray(),
            'overrides' => $this->activeOverrides($tenantId, $customerId),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertConsistent(array $data): void
    {
        $errors = [];

        if (! empty($data['valid_to']) && CarbonImmutable::parse((string) $data['valid_to'])->lt(CarbonImmutable::parse((string) $data['valid_from']))) {
            $errors['valid_to'] = __('receivables.limit_period_invalid');
        }

        if (isset($data['insured_limit']) && Decimal::isPositive((string) $data['insured_limit']) && (empty($data['policy_number']) && empty($data['insurer_name']))) {
            $errors['policy_number'] = __('receivables.insurance_policy_required');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'customer_id', 'currency', 'granted_limit', 'insured_limit', 'insurer_name', 'policy_number', 'coverage_percentage',
            'max_overdue_days', 'overdue_tolerance_amount', 'reaction_policy', 'valid_from', 'valid_to', 'is_active', 'notes',
        ]));
    }

    private function syncProfile(string $tenantId, string $customerId, ?string $userId): void
    {
        $current = $this->effective($tenantId, $customerId);
        $granted = $current instanceof CustomerCreditLimit ? (string) $current->granted_limit : '0';

        $this->legacyCredit->updateCreditLimit($customerId, $granted, $userId ?? 'system', $tenantId);
    }
}

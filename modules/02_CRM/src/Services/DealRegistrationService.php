<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CRM\Models\DealRegistration;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Partner;

/**
 * Deal registration: a partner registers a customer deal and is protected while the approved registration has not
 * expired. A second registration for the same customer (same normalised name) stays pending with a pointer to the live
 * registration and cannot be approved until that one expires, is rejected or is won.
 */
final class DealRegistrationService
{
    private const int DefaultProtectionDays = 90;

    /**
     * @param  array{partner_id: string, customer_name: string, expected_amount?: string|float|int|null, currency?: string, registered_on?: string, expires_on?: string|null, opportunity_id?: string|null}  $data
     */
    public function submit(string $tenantId, array $data): DealRegistration
    {
        $partner = Partner::query()->where('tenant_id', $tenantId)->findOrFail($data['partner_id']);

        if ($partner->status !== 'active') {
            throw ValidationException::withMessages(['partner_id' => 'Only an active partner can register deals.']);
        }

        $registeredOn = $data['registered_on'] ?? now()->toDateString();
        $key = $this->customerKey($data['customer_name']);

        return DealRegistration::create([
            'tenant_id' => $tenantId,
            'partner_id' => $partner->id,
            'opportunity_id' => $data['opportunity_id'] ?? null,
            'customer_name' => $data['customer_name'],
            'customer_key' => $key,
            'expected_amount' => $data['expected_amount'] ?? 0,
            'currency' => $data['currency'] ?? 'EUR',
            'status' => 'pending',
            'conflicts_with_id' => $this->liveRegistration($tenantId, $key)?->id,
            'registered_on' => $registeredOn,
            'expires_on' => $data['expires_on'] ?? now()->parse($registeredOn)->addDays(self::DefaultProtectionDays)->toDateString(),
        ]);
    }

    public function approve(DealRegistration $registration, string $userId): DealRegistration
    {
        $this->assertStatus($registration, ['pending']);
        $live = $this->liveRegistration((string) $registration->tenant_id, $registration->customer_key, $registration->id);

        if ($live !== null) {
            throw ValidationException::withMessages(['status' => 'Another approved registration already protects this customer.']);
        }

        return $this->decide($registration, 'approved', $userId, null);
    }

    public function reject(DealRegistration $registration, string $userId, string $reason): DealRegistration
    {
        $this->assertStatus($registration, ['pending', 'approved']);

        return $this->decide($registration, 'rejected', $userId, $reason);
    }

    /**
     * An approved registration is won; a linked opportunity must be won as well.
     */
    public function markWon(DealRegistration $registration): DealRegistration
    {
        $this->assertStatus($registration, ['approved']);

        if ($registration->opportunity_id !== null) {
            $status = Opportunity::query()->where('tenant_id', $registration->tenant_id)->whereKey($registration->opportunity_id)->value('status');

            if ($status !== 'won') {
                throw ValidationException::withMessages(['opportunity_id' => 'The linked opportunity is not won.']);
            }
        }

        $registration->forceFill(['status' => 'won'])->save();

        return $registration;
    }

    /**
     * Expires the approved registrations whose protection ended. Returns how many.
     */
    public function expireOverdue(string $tenantId): int
    {
        return DealRegistration::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->whereDate('expires_on', '<', now()->toDateString())
            ->update(['status' => 'expired']);
    }

    public function customerKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name)) ?? '';
    }

    private function liveRegistration(string $tenantId, string $key, ?string $exceptId = null): ?DealRegistration
    {
        return DealRegistration::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_key', $key)
            ->where('status', 'approved')
            ->whereDate('expires_on', '>=', now()->toDateString())
            ->when($exceptId !== null, static fn ($query) => $query->whereKeyNot($exceptId))
            ->first();
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(DealRegistration $registration, array $allowed): void
    {
        if (! in_array($registration->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'The registration is '.$registration->status.' and cannot take this action.']);
        }
    }

    private function decide(DealRegistration $registration, string $status, string $userId, ?string $reason): DealRegistration
    {
        return DB::transaction(function () use ($registration, $status, $userId, $reason): DealRegistration {
            $registration->forceFill(['status' => $status, 'decided_by_user_id' => $userId, 'decided_at' => now(), 'decision_reason' => $reason])->save();

            return $registration;
        });
    }
}

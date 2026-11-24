<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Illuminate\Support\Facades\DB;

/**
 * Credit semaphore (Mod 028) bridge: customers blocked for arrears, with an open collection case or in dispute never
 * receive promotional outreach.
 */
final class InsolvencyGuard
{
    /**
     * @return array<string, true> contact ids to exclude, as set
     */
    public function blockedContactIds(string $tenantId, float $maxOverdueBalance = 0.0): array
    {
        $blocked = [];

        $profiles = DB::table('customer_credit_profiles')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where(static function ($q) use ($maxOverdueBalance): void {
                $q->where('is_credit_hold', true)
                    ->orWhereIn('credit_status', ['on_hold', 'rejected', 'blocked'])
                    ->orWhere('overdue_amount', '>', $maxOverdueBalance);
            })
            ->pluck('customer_id');

        $cases = DB::table('credit_collection_cases')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNull('closed_on')
            ->pluck('customer_id');

        $disputes = DB::table('credit_disputes')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNull('resolved_on')
            ->pluck('customer_id');

        foreach ([$profiles, $cases, $disputes] as $ids) {
            foreach ($ids as $id) {
                $blocked[(string) $id] = true;
            }
        }

        return $blocked;
    }

    public function isBlocked(string $tenantId, string $contactId, float $maxOverdueBalance = 0.0): bool
    {
        return isset($this->blockedContactIds($tenantId, $maxOverdueBalance)[$contactId]);
    }
}

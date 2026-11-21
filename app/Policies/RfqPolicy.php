<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Models\User;
use Modules\Procurement\Models\Rfq;

/**
 * Authorization policy guarding tenant isolation and lifecycle permissions on RFQs.
 */
final class RfqPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RequestForQuotation|Rfq $rfq): bool
    {
        return $this->sameTenant($user, $rfq);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RequestForQuotation|Rfq $rfq): bool
    {
        return $this->sameTenant($user, $rfq);
    }

    public function publish(User $user, RequestForQuotation|Rfq $rfq): bool
    {
        return $this->sameTenant($user, $rfq);
    }

    public function extendDeadline(User $user, RequestForQuotation|Rfq $rfq): bool
    {
        return $this->sameTenant($user, $rfq);
    }

    public function award(User $user, RequestForQuotation|Rfq $rfq): bool
    {
        return $this->sameTenant($user, $rfq);
    }

    public function cancel(User $user, RequestForQuotation|Rfq $rfq): bool
    {
        return $this->sameTenant($user, $rfq);
    }

    private function sameTenant(User $user, RequestForQuotation|Rfq $rfq): bool
    {
        if (! empty($user->tenant_id) && ! empty($rfq->tenant_id) && (string) $user->tenant_id !== (string) $rfq->tenant_id) {
            return false;
        }

        if (! empty($rfq->tenant_id) && $user->tenants()->exists() && ! $user->belongsToTenant((string) $rfq->tenant_id)) {
            return false;
        }

        return true;
    }
}

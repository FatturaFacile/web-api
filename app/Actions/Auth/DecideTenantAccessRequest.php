<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\TenantAccessRequestNotDecidableException;
use App\Mail\TenantAccessDecisionMail;
use App\Models\Tenant;
use App\Models\TenantAccessRequest;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\TaxIdentifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The decision of a platform administrator on a registration: only here a user gets access to a tenant. A new company
 * becomes a tenant at this point, so requests that are moved or rejected leave nothing behind.
 */
class DecideTenantAccessRequest
{
    public function __construct(private readonly RegistrationAudit $audit) {}

    /**
     * Confirm the matched company, or create it when the registration did not match any tenant.
     *
     * @throws TenantAccessRequestNotDecidableException
     */
    public function approve(string $requestId, User $admin, ?string $role = null): TenantAccessRequest
    {
        return $this->decide($requestId, function (TenantAccessRequest $request) use ($admin, $role): void {
            $tenant = $request->tenant ?? TaxIdentifier::findTenant($request->requested_tax_id);
            $isNewTenant = $tenant === null;
            $tenant ??= $this->createTenant($request);

            $this->grantAccess($request, $tenant, $role ?? ($isNewTenant ? 'owner' : 'member'), $admin);
            $this->audit->record(RegistrationAudit::ACCESS_APPROVED, $request->user_id, ['new_tenant' => $isNewTenant], $tenant->id);
        });
    }

    /**
     * Put the user on a different existing tenant than the one requested.
     *
     * @throws TenantAccessRequestNotDecidableException
     */
    public function move(string $requestId, User $admin, Tenant $tenant, ?string $role = null): TenantAccessRequest
    {
        return $this->decide($requestId, function (TenantAccessRequest $request) use ($admin, $tenant, $role): void {
            $this->grantAccess($request, $tenant, $role ?? 'member', $admin);
            $this->audit->record(RegistrationAudit::ACCESS_MOVED, $request->user_id, [], $tenant->id);
        });
    }

    /**
     * @throws TenantAccessRequestNotDecidableException
     */
    public function reject(string $requestId, User $admin, ?string $reason = null): TenantAccessRequest
    {
        return $this->decide($requestId, function (TenantAccessRequest $request) use ($admin, $reason): void {
            $request->update([
                'status' => TenantAccessRequest::REJECTED,
                'tenant_id' => null,
                'decided_by' => $admin->id,
                'decided_at' => Carbon::now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->record(RegistrationAudit::ACCESS_REJECTED, $request->user_id);
        });
    }

    /**
     * @param  callable(TenantAccessRequest): void  $decision
     *
     * @throws TenantAccessRequestNotDecidableException
     */
    private function decide(string $requestId, callable $decision): TenantAccessRequest
    {
        $decided = DB::transaction(function () use ($requestId, $decision): TenantAccessRequest {
            /** @var TenantAccessRequest $request */
            $request = TenantAccessRequest::query()->with('user')->lockForUpdate()->findOrFail($requestId);

            if (! $request->isPending() || $request->user->email_verified_at === null) {
                throw new TenantAccessRequestNotDecidableException;
            }

            $decision($request);

            return $request->refresh();
        });

        // Outside the transaction: a decision that is rolled back must not announce anything.
        Mail::to($decided->user->email)
            ->locale($decided->user->locale)
            ->queue(new TenantAccessDecisionMail($decided->user, $decided->status));

        return $decided;
    }

    private function grantAccess(TenantAccessRequest $request, Tenant $tenant, string $role, User $admin): void
    {
        if (! $request->user->belongsToTenant($tenant->id)) {
            $request->user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
        }

        $request->update([
            'status' => TenantAccessRequest::APPROVED,
            'tenant_id' => $tenant->id,
            'decided_by' => $admin->id,
            'decided_at' => Carbon::now(),
        ]);
    }

    private function createTenant(TenantAccessRequest $request): Tenant
    {
        $isSwiss = $request->requested_country === 'CH';

        return Tenant::create([
            'name' => $request->requested_company_name,
            'legal_name' => $request->requested_company_name,
            'country' => $request->requested_country,
            'default_currency' => $isSwiss ? 'CHF' : 'EUR',
            'vat_number' => $isSwiss ? null : $request->requested_tax_id,
            'uid_number' => $isSwiss ? TaxIdentifier::formatUid($request->requested_tax_id) : null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;

/**
 * Account-less access to the landing page and the pass of a beneficiary. The slug of a convention (unique across companies) and the
 * unguessable wallet pass id select the company; everything else runs inside that company only. A convention that is not
 * in force answers like one that does not exist, so nothing is disclosed about drafts or expired agreements.
 */
final class PublicAccessService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @template TReturn
     *
     * @param  callable(CorporatePartnerConvention): TReturn  $callback
     * @return TReturn
     */
    public function withConvention(string $slug, callable $callback, bool $requireOpen = true): mixed
    {
        $found = CorporatePartnerConvention::withoutGlobalScopes()->whereNull('deleted_at')->where('slug', $slug)->first();

        if ($found === null || $found->tenant_id === null) {
            throw new ConventionException('convention_not_found', 404);
        }

        return $this->inTenant((string) $found->tenant_id, function () use ($found, $callback, $requireOpen): mixed {
            $convention = CorporatePartnerConvention::query()->with('discountRules')->findOrFail($found->id);

            if ($requireOpen && ! $convention->isActiveOn(now())) {
                throw new ConventionException('convention_not_found', 404);
            }

            return $callback($convention);
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(ConventionBeneficiaryEnrollment): TReturn  $callback
     * @return TReturn
     */
    public function withPass(string $walletPassId, callable $callback): mixed
    {
        $found = ConventionBeneficiaryEnrollment::withoutGlobalScopes()->whereNull('deleted_at')->where('wallet_pass_id', $walletPassId)->first();

        if ($found === null || $found->tenant_id === null) {
            throw new ConventionException('pass_not_found', 404);
        }

        return $this->inTenant((string) $found->tenant_id, function () use ($found, $callback): mixed {
            $enrollment = ConventionBeneficiaryEnrollment::query()->with(['convention', 'familyMembers'])->findOrFail($found->id);

            return $callback($enrollment);
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(string $tenantId, callable $callback): mixed
    {
        return $this->tenantContext->runWithTenant(Tenant::query()->findOrFail($tenantId), $callback);
    }
}

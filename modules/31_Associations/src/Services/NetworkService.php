<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\NetworkLink;

/**
 * The view of a parent body over its affiliates. Every company stays a tenant of its own: the parent never queries the
 * data of an affiliate, this service does, one affiliate at a time, inside the tenant of the affiliate and only after
 * reading its consent. What comes out is counts, never people.
 */
final class NetworkService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * The code this company gives to a parent body to be found. It holds no information about the company.
     */
    public function affiliationCode(): string
    {
        $settings = AssociationSetting::current();

        if ($settings->network_code === null) {
            $settings->update(['network_code' => Str::lower(Str::random(32))]);
        }

        return (string) $settings->network_code;
    }

    /**
     * A parent asks to be linked. Nothing is visible until the affiliate grants its consent.
     */
    public function requestAffiliation(string $affiliationCode): NetworkLink
    {
        $parentId = (string) $this->tenantContext->getTenantId();

        /** @var ?AssociationSetting $affiliate */
        $affiliate = AssociationSetting::withoutGlobalScopes()->whereNull('deleted_at')->where('network_code', $affiliationCode)->first();

        if ($affiliate === null) {
            throw new AssociationException(__('associations.network_code_unknown'));
        }

        if ($affiliate->tenant_id === $parentId) {
            throw new AssociationException(__('associations.network_self'));
        }

        $tenant = Tenant::query()->findOrFail($affiliate->tenant_id);

        return $this->tenantContext->runWithTenant($tenant, static function () use ($parentId): NetworkLink {
            $link = NetworkLink::query()->where('parent_tenant_id', $parentId)->first();

            if ($link !== null) {
                throw AssociationException::conflict(__('associations.network_already_linked'));
            }

            return NetworkLink::create([
                'parent_tenant_id' => $parentId,
                'status' => NetworkLink::Pending,
                'requested_at' => now(),
            ]);
        });
    }

    public function grantConsent(NetworkLink $link, ?string $userId): NetworkLink
    {
        $link->update([
            'status' => NetworkLink::Active,
            'consent_granted_at' => now(),
            'consent_granted_by' => $userId,
            'consent_withdrawn_at' => null,
            'consent_withdrawn_by' => null,
        ]);

        return $link;
    }

    public function withdrawConsent(NetworkLink $link, ?string $userId): NetworkLink
    {
        $link->update(['status' => NetworkLink::Withdrawn, 'consent_withdrawn_at' => now(), 'consent_withdrawn_by' => $userId]);

        return $link;
    }

    /**
     * The affiliates of the active company with a consent in force, and their figures.
     *
     * @return array{affiliates: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function figures(): array
    {
        $affiliates = [];
        $totals = ['members' => 0, 'active_memberships' => 0, 'active_courses' => 0, 'course_participants' => 0];

        foreach ($this->consentingAffiliates() as $tenant) {
            $figures = $this->tenantContext->runWithTenant($tenant, fn (): array => $this->countsOfCurrentTenant());
            $affiliates[] = ['tenant_id' => $tenant->id, 'name' => $tenant->name, 'figures' => $figures];

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $figures[$key];
            }
        }

        return ['affiliates' => $affiliates, 'totals' => $totals];
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function consentingAffiliates(): Collection
    {
        $affiliateIds = NetworkLink::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('parent_tenant_id', $this->tenantContext->getTenantId())
            ->where('status', NetworkLink::Active)
            ->whereNotNull('consent_granted_at')
            ->pluck('tenant_id');

        return Tenant::query()->whereIn('id', $affiliateIds)->orderBy('name')->get();
    }

    /**
     * @return array{members: int, active_memberships: int, active_courses: int, course_participants: int}
     */
    private function countsOfCurrentTenant(): array
    {
        return [
            'members' => Member::query()->where('status', MemberStatus::Active)->count(),
            'active_memberships' => Membership::query()->where('status', MembershipStatus::Active)->count(),
            'active_courses' => Course::query()->where('status', Course::Active)->count(),
            'course_participants' => CourseEnrolment::query()->where('status', CourseEnrolmentStatus::Enrolled)->distinct()->count('member_id'),
        ];
    }
}

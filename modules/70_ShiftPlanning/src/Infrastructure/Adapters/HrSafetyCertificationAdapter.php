<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Adapters;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\Hse\Domain\Contracts\HseClearanceGatekeeperInterface;
use Modules\Hse\Domain\Enums\ClearanceContext;
use Modules\Hse\Domain\Exceptions\HseNotFoundException;
use Modules\Hse\Domain\ValueObjects\ClearanceRequest;
use Modules\HumanResources\Models\Certification;
use Modules\ShiftPlanning\Domain\Contracts\SafetyCertificationPort;
use Modules\ShiftPlanning\Domain\ValueObjects\CertificationIssue;

/**
 * Reads the certifications of the HR training module (Mod 099): a mandatory certificate that is revoked, or that expired
 * before the shift date, makes the employee unfit for the shift. A certificate without an expiry date does not expire.
 * The HSE clearance (role requirement matrices, physician judgment, PPE) is merged in: whatever blocks a dispatch blocks the shift.
 */
final class HrSafetyCertificationAdapter implements SafetyCertificationPort
{
    public function __construct(
        private readonly HseClearanceGatekeeperInterface $gatekeeper,
        private readonly TenantContext $tenants,
    ) {}

    public function invalidMandatoryCertifications(string $employeeId, CarbonImmutable $date): array
    {
        $certifications = Certification::query()
            ->where('employee_id', $employeeId)
            ->where('is_mandatory', true)
            ->get()
            ->filter(static fn (Certification $certification): bool => $certification->status === 'revoked'
                || ($certification->expiry_date !== null && $certification->expiry_date->format('Y-m-d') < $date->format('Y-m-d')))
            ->map(static fn (Certification $certification): CertificationIssue => new CertificationIssue(
                (string) $certification->code,
                (string) $certification->name,
                $certification->expiry_date === null ? null : CarbonImmutable::parse($certification->expiry_date->format('Y-m-d')),
            ))
            ->values()
            ->all();

        return [...$certifications, ...$this->hseBlockingReasons($employeeId, $date)];
    }

    /**
     * Role level HSE clearance: expired or missing training, medical fitness and PPE required for the position of the employee.
     *
     * @return list<CertificationIssue>
     */
    private function hseBlockingReasons(string $employeeId, CarbonImmutable $date): array
    {
        $tenantId = $this->tenants->getTenantId();

        if ($tenantId === null) {
            return [];
        }

        try {
            $result = $this->gatekeeper->evaluate(new ClearanceRequest($tenantId, $employeeId, ClearanceContext::ProductionWorkCenter, '', $date));
        } catch (HseNotFoundException) {
            return [];
        }

        return array_map(
            static fn ($reason): CertificationIssue => new CertificationIssue(
                $reason->code->value.':'.$reason->subject,
                $reason->subject,
                $reason->expiresAt === null ? null : CarbonImmutable::parse($reason->expiresAt),
            ),
            $result->blockingReasons,
        );
    }
}

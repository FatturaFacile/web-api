<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use Illuminate\Support\Carbon;
use Modules\GreenCare\Domain\Compliance\IGreenCareComplianceStrategy;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;
use Modules\GreenCare\Models\CrewTeamMember;
use Modules\GreenCare\Models\OperatorPhytosanitaryLicense;

/**
 * Decides whether an operator may apply phytosanitary products on a day: he needs an active license of a type that the jurisdiction
 * accepts (PER / PNRP / OPF in Switzerland, patentino PAN in Italy) that is valid on the day of the treatment.
 */
final class OperatorLicenseGuard
{
    /**
     * @return array{valid: bool, license: OperatorPhytosanitaryLicense|null, reason: string|null}
     */
    public function check(string $operatorId, Carbon $date, IGreenCareComplianceStrategy $strategy): array
    {
        CrewTeamMember::query()->findOrFail($operatorId);

        $accepted = array_map(static fn ($type): string => $type->value, $strategy->acceptedLicenseTypes());

        /** @var \Illuminate\Database\Eloquent\Collection<int, OperatorPhytosanitaryLicense> $licenses */
        $licenses = OperatorPhytosanitaryLicense::query()
            ->where('crew_team_member_id', $operatorId)
            ->where('is_active', true)
            ->whereIn('license_type', $accepted)
            ->orderByDesc('expiry_date')
            ->get();

        foreach ($licenses as $license) {
            if ($license->isValidOn($date)) {
                return ['valid' => true, 'license' => $license, 'reason' => null];
            }
        }

        return ['valid' => false, 'license' => $licenses->first(), 'reason' => $licenses->isEmpty() ? 'operator_license_missing' : 'operator_license_expired'];
    }

    /**
     * @throws GreenCareException operator_license_missing / operator_license_expired
     */
    public function assertValid(string $operatorId, Carbon $date, IGreenCareComplianceStrategy $strategy): OperatorPhytosanitaryLicense
    {
        $result = $this->check($operatorId, $date, $strategy);

        if (! $result['valid'] || $result['license'] === null) {
            throw GreenCareException::because((string) $result['reason'], [
                'jurisdiction' => $strategy->jurisdiction(),
                'date' => $date->format('Y-m-d'),
                'expiry' => $result['license']?->expiry_date->format('Y-m-d') ?? '-',
            ]);
        }

        return $result['license'];
    }
}

<?php

declare(strict_types=1);

namespace Modules\GreenCare\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\GreenCare\Domain\Enums\LicenseType;

/**
 * Certification that allows an operator to apply phytosanitary products: PER / PNRP / OPF in Switzerland, patentino PAN in Italy.
 *
 * @property string $crew_team_member_id
 * @property LicenseType $license_type
 * @property string $jurisdiction
 * @property string $license_number
 * @property string|null $issuing_authority
 * @property Carbon $issue_date
 * @property Carbon $expiry_date
 * @property bool $is_active
 */
final class OperatorPhytosanitaryLicense extends BaseEnterpriseModel
{
    protected $table = 'operator_phytosanitary_licenses';

    protected function casts(): array
    {
        return [
            'license_type' => LicenseType::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CrewTeamMember, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(CrewTeamMember::class, 'crew_team_member_id');
    }

    /**
     * Valid on a day: active, issued on or before it and not expired (the expiry day is the last valid day).
     */
    public function isValidOn(Carbon $date): bool
    {
        return $this->is_active
            && $this->issue_date->copy()->startOfDay()->lte($date)
            && $this->expiry_date->copy()->endOfDay()->gte($date);
    }
}

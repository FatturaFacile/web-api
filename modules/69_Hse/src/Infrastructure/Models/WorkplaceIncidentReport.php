<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Hse\Domain\Enums\IncidentStatus;
use Modules\Hse\Domain\Enums\IncidentType;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\ValueObjects\InjuryDynamics;

/**
 * Near-miss and accident registry entry.
 *
 * @property string $protocol_number
 * @property Jurisdiction $jurisdiction
 * @property Carbon $incident_date
 * @property Carbon $reported_at
 * @property string|null $location_or_site_id
 * @property string|null $work_center_id
 * @property IncidentType $incident_type
 * @property list<string>|null $involved_employee_ids_json
 * @property list<string>|null $witness_employee_ids_json
 * @property string $description
 * @property string|null $immediate_actions_taken
 * @property array<string, mixed>|null $root_cause_analysis_json
 * @property list<array<string, mixed>>|null $corrective_actions_json
 * @property int $prognosis_days
 * @property array<string, mixed>|null $injury_dynamics_json
 * @property string|null $linked_insurance_claim_id
 * @property IncidentStatus $status
 */
final class WorkplaceIncidentReport extends BaseEnterpriseModel
{
    protected $table = 'hse_incident_reports';

    protected function casts(): array
    {
        return [
            'jurisdiction' => Jurisdiction::class,
            'incident_type' => IncidentType::class,
            'status' => IncidentStatus::class,
            'incident_date' => 'datetime',
            'reported_at' => 'datetime',
            'involved_employee_ids_json' => 'array',
            'witness_employee_ids_json' => 'array',
            'root_cause_analysis_json' => 'array',
            'corrective_actions_json' => 'array',
            'injury_dynamics_json' => 'array',
            'prognosis_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<IncidentPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(IncidentPhoto::class, 'incident_report_id');
    }

    public function injuryDynamics(): ?InjuryDynamics
    {
        return InjuryDynamics::fromArray($this->injury_dynamics_json);
    }

    public function isReportable(): bool
    {
        return $this->incident_type->isReportable($this->jurisdiction, $this->prognosis_days);
    }
}

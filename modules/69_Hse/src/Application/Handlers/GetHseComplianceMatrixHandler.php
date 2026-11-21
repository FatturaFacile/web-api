<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Hse\Application\Queries\GetHseComplianceMatrixQuery;
use Modules\Hse\Domain\Enums\CertificateStatus;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Enums\IncidentStatus;
use Modules\Hse\Domain\Enums\IncidentType;
use Modules\Hse\Domain\Enums\PpeDeliveryStatus;
use Modules\Hse\Domain\Enums\TrafficLight;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\Hse\Infrastructure\Models\OccupationalMedicalVisit;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;
use Modules\Hse\Infrastructure\Models\SafetyRoleRequirementMatrix;
use Modules\Hse\Infrastructure\Models\WorkplaceIncidentReport;
use Modules\HumanResources\Models\Employee;

/**
 * The RSPP/SiBe traffic-light matrix: employees by trainings, medical fitness and PPE with an overall light. GREEN is
 * compliant, YELLOW expires within 60 days or carries prescriptions, RED is expired, missing, unfit or blocked.
 * Traffic lights are computed for all the employees of the filter, then filtered and paginated, because the status
 * filter depends on the computed values; the data is loaded in four queries whatever the page size.
 */
final class GetHseComplianceMatrixHandler
{
    private const int ExpiringDays = 60;

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, kpis: array<string, int|float>}
     */
    public function handle(GetHseComplianceMatrixQuery $query): array
    {
        $day = $query->asOf->startOfDay();

        $matrices = SafetyRoleRequirementMatrix::query()->where('tenant_id', $query->tenantId)->where('is_active', true)
            ->when($query->jurisdiction !== null, fn ($q) => $q->where('jurisdiction', $query->jurisdiction?->value))->get();

        $employees = Employee::query()->where('tenant_id', $query->tenantId)
            ->when($query->departmentId !== null, fn ($q) => $q->where('department_id', $query->departmentId))
            ->whereNull('termination_date')->orderBy('last_name')->orderBy('first_name')->get();

        $ids = $employees->pluck('id')->all();
        $certificates = EmployeeSafetyCertificate::query()->where('tenant_id', $query->tenantId)->whereIn('employee_id', $ids)->whereNull('revoked_at')->get()->groupBy('employee_id');
        $visits = OccupationalMedicalVisit::query()->where('tenant_id', $query->tenantId)->whereIn('employee_id', $ids)->whereDate('visit_date', '<=', $day->toDateString())
            ->orderByDesc('visit_date')->orderByDesc('created_at')->get()->unique('employee_id')->keyBy('employee_id');
        $ppe = PpeDistributionRecord::query()->where('tenant_id', $query->tenantId)->whereIn('employee_id', $ids)->where('delivery_status', PpeDeliveryStatus::DeliveredSigned->value)->get()->groupBy('employee_id');

        $rows = $employees->map(fn (Employee $employee): array => $this->row($employee, $matrices, $query, $day, $certificates->get($employee->id, collect()), $visits->get($employee->id), $ppe->get($employee->id, collect())));

        $kpis = $this->kpis($rows, $query, $day);

        if ($query->status !== null) {
            $rows = $rows->filter(fn (array $row): bool => $row['overall'] === $query->status->value);
        }

        $total = $rows->count();
        $perPage = max(1, min(200, $query->perPage));
        $page = max(1, $query->page);

        return [
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'meta' => ['total' => $total, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage))],
            'kpis' => $kpis,
        ];
    }

    /**
     * @param  Collection<int, SafetyRoleRequirementMatrix>  $matrices
     * @param  Collection<int, EmployeeSafetyCertificate>  $certificates
     * @param  Collection<int, PpeDistributionRecord>  $ppe
     * @return array<string, mixed>
     */
    private function row(Employee $employee, Collection $matrices, GetHseComplianceMatrixQuery $query, CarbonImmutable $day, Collection $certificates, ?OccupationalMedicalVisit $visit, Collection $ppe): array
    {
        $applicable = $matrices->filter(fn (SafetyRoleRequirementMatrix $matrix): bool => ($matrix->job_position_id === null || $matrix->job_position_id === $employee->position_id)
            && ($matrix->work_center_id === null || $matrix->work_center_id === $query->workCenterId)
            && ($matrix->construction_site_id === null || $matrix->construction_site_id === $query->constructionSiteId));

        $trainings = [];

        foreach ($applicable->flatMap(fn (SafetyRoleRequirementMatrix $matrix): array => $matrix->requiredTrainings()) as $training) {
            $type = $training['type'];
            $held = $certificates->filter(fn (EmployeeSafetyCertificate $c): bool => $c->certificate_type === $type);
            $best = $held->sortByDesc(fn (EmployeeSafetyCertificate $c): string => $c->expires_at?->toDateString() ?? '9999-12-31')->first();
            $trainings[$type->value] = $best === null
                ? ['light' => TrafficLight::Red->value, 'expires_at' => null, 'missing' => true]
                : ['light' => $this->light($best->statusAt($day)), 'expires_at' => $best->expires_at?->toDateString(), 'missing' => false];
        }

        $examsRequired = $applicable->contains(fn (SafetyRoleRequirementMatrix $matrix): bool => ($matrix->required_medical_exam_codes_json ?? []) !== []);
        $medical = $this->medical($visit, $examsRequired, $day);

        $ppeCells = [];

        foreach ($applicable->flatMap(fn (SafetyRoleRequirementMatrix $matrix): array => $matrix->required_ppe_product_ids_json ?? [])->unique() as $productId) {
            $latest = $ppe->where('product_id', $productId)->sortByDesc(fn (PpeDistributionRecord $r): string => $r->replacement_due_at?->toDateString() ?? '9999-12-31')->first();
            $due = $latest?->replacement_due_at;
            $ppeCells[$productId] = [
                'light' => $latest === null ? TrafficLight::Red->value : ($due === null ? TrafficLight::Green->value : $this->dateLight($due->toDateString(), $day)->value),
                'replacement_due_at' => $due?->toDateString(),
                'missing' => $latest === null,
            ];
        }

        $overall = TrafficLight::Green;

        foreach ([...array_column($trainings, 'light'), $medical['light'], ...array_column($ppeCells, 'light')] as $light) {
            $overall = $overall->worst(TrafficLight::from($light));
        }

        return [
            'employee_id' => (string) $employee->id,
            'employee_name' => trim($employee->first_name.' '.$employee->last_name),
            'department_id' => $employee->department_id,
            'trainings' => $trainings,
            'medical' => $medical,
            'ppe' => $ppeCells,
            'overall' => $overall->value,
            'is_hard_blocked' => $overall === TrafficLight::Red,
        ];
    }

    /**
     * @return array{light: string, fitness_outcome: string|null, restrictions_summary: string|null, next_visit_due_date: string|null}
     */
    private function medical(?OccupationalMedicalVisit $visit, bool $required, CarbonImmutable $day): array
    {
        if ($visit === null) {
            return ['light' => ($required ? TrafficLight::Red : TrafficLight::Green)->value, 'fitness_outcome' => null, 'restrictions_summary' => null, 'next_visit_due_date' => null];
        }

        $due = $visit->next_visit_due_date?->toDateString();
        $light = match (true) {
            $visit->fitness_outcome->blocksDispatch() => TrafficLight::Red,
            $due !== null && $required => $this->dateLight($due, $day),
            default => TrafficLight::Green,
        };

        if ($visit->fitness_outcome === FitnessOutcome::FitWithPrescriptions) {
            $light = $light->worst(TrafficLight::Yellow);
        }

        return ['light' => $light->value, 'fitness_outcome' => $visit->fitness_outcome->value, 'restrictions_summary' => $visit->restrictions_summary, 'next_visit_due_date' => $due];
    }

    private function light(CertificateStatus $status): string
    {
        return match ($status) {
            CertificateStatus::Valid => TrafficLight::Green->value,
            CertificateStatus::ExpiringSoon => TrafficLight::Yellow->value,
            CertificateStatus::Expired, CertificateStatus::Revoked => TrafficLight::Red->value,
        };
    }

    private function dateLight(string $due, CarbonImmutable $day): TrafficLight
    {
        $left = (int) $day->diffInDays(CarbonImmutable::parse($due)->startOfDay(), false);

        return $left < 0 ? TrafficLight::Red : ($left <= self::ExpiringDays ? TrafficLight::Yellow : TrafficLight::Green);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    private function kpis(Collection $rows, GetHseComplianceMatrixQuery $query, CarbonImmutable $day): array
    {
        $total = $rows->count();
        $green = $rows->where('overall', TrafficLight::Green->value)->count();
        $soon = $day->addDays(30)->toDateString();

        $expiring30 = EmployeeSafetyCertificate::query()->where('tenant_id', $query->tenantId)->whereNull('revoked_at')
            ->whereDate('expires_at', '>=', $day->toDateString())->whereDate('expires_at', '<=', $soon)->count();

        $openIncidents = WorkplaceIncidentReport::query()->where('tenant_id', $query->tenantId)
            ->where('status', '!=', IncidentStatus::Closed->value)
            ->where(fn ($q) => $q->where('incident_type', IncidentType::NearMiss->value)->orWhereNotNull('corrective_actions_json'))->count();

        return [
            'overall_compliance_percent' => $total === 0 ? 100.0 : round($green / $total * 100, 1),
            'hard_blocked_workers' => $rows->where('is_hard_blocked', true)->count(),
            'expiring_in_30_days' => $expiring30,
            'open_near_miss_and_capas' => $openIncidents,
        ];
    }
}

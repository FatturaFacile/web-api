<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Hse\Domain\Contracts\HseClearanceGatekeeperInterface;
use Modules\Hse\Domain\Contracts\Mod074AccidentClaimBridgeInterface;
use Modules\Hse\Domain\Enums\BlockingReasonCode;
use Modules\Hse\Domain\Enums\ClearanceContext;
use Modules\Hse\Domain\Enums\IncidentType;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Enums\PpeDeliveryStatus;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Domain\ValueObjects\BlockingReason;
use Modules\Hse\Domain\ValueObjects\ClearanceRequest;
use Modules\Hse\Infrastructure\Models\AccidentClaimDraft;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;
use Modules\Hse\Infrastructure\Models\SafetyRoleRequirementMatrix;
use Modules\Hse\Infrastructure\Models\WorkplaceIncidentReport;
use Modules\HumanResources\Models\Employee;

/**
 * Anti-corruption adapter to Payroll, Social Security and Statutory Declarations (Mod 074). It maps an incident to the
 * SUVA Sunet (CH, UVG/LAINF) or INAIL (IT) declaration schema and registers the draft; the insurer identifiers that
 * only the company knows come from the "hse" block of the tenant settings (suva_customer_number, operating_unit_code,
 * municipality_bfs, canton, inail_company_code, inail_pat, inail_tariff_item, ateco2007, istat_municipality, ccnl_code).
 */
final class Mod074AccidentClaimBridge implements Mod074AccidentClaimBridgeInterface
{
    private const array TRAINING_REASONS = [BlockingReasonCode::CertificateMissing, BlockingReasonCode::CertificateExpired];

    public function __construct(private readonly HseClearanceGatekeeperInterface $gatekeeper) {}

    public function buildPayload(WorkplaceIncidentReport $incident): array
    {
        $employeeId = ($incident->involved_employee_ids_json ?? [])[0] ?? throw new HseException('The incident has no injured employee.');
        $employee = Employee::query()->where('tenant_id', $incident->tenant_id)->whereKey($employeeId)->first() ?? throw new HseException('Unknown injured employee.');
        $tenant = Tenant::query()->findOrFail($incident->tenant_id);

        return $incident->jurisdiction === Jurisdiction::Switzerland
            ? $this->suvaPayload($incident, $employee, $tenant)
            : $this->inailPayload($incident, $employee, $tenant);
    }

    public function createDraft(WorkplaceIncidentReport $incident): AccidentClaimDraft
    {
        $payload = $this->buildPayload($incident);

        return DB::transaction(function () use ($incident, $payload): AccidentClaimDraft {
            $attributes = [
                'jurisdiction' => $incident->jurisdiction,
                'schema_version' => $payload['schemaVersion'],
                'declaration_type' => $payload['declarationType'] ?? null,
                'payload_json' => $payload,
                'status' => 'draft',
            ];

            $draft = AccidentClaimDraft::query()->where('incident_report_id', $incident->id)->first();

            if ($draft === null) {
                $draft = AccidentClaimDraft::create(['tenant_id' => $incident->tenant_id, 'incident_report_id' => $incident->id, ...$attributes]);
            } else {
                $draft->update($attributes);
            }

            $incident->update(['linked_insurance_claim_id' => $draft->id]);

            return $draft;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function suvaPayload(WorkplaceIncidentReport $incident, Employee $employee, Tenant $tenant): array
    {
        $settings = $this->settings($tenant);
        $dynamics = $incident->injuryDynamics();
        $when = CarbonImmutable::parse($incident->incident_date)->setTimezone('Europe/Zurich');
        $ppe = $this->ppeWorn($incident, $employee);

        return [
            'schemaVersion' => Jurisdiction::Switzerland->claimSchemaVersion(),
            'sourceModule' => 'MOD-099',
            'incidentReportId' => $incident->id,
            'protocolNumber' => $incident->protocol_number,
            'employer' => [
                'uid' => $tenant->uid_number,
                'suvaCustomerNumber' => $settings['suva_customer_number'] ?? null,
                'suvaHazardClass' => $this->hazardClass($incident),
                'operatingUnitCode' => $settings['operating_unit_code'] ?? null,
            ],
            'insuredPerson' => [
                'employeeId' => $employee->id,
                'ahvNumber' => $employee->social_security_number,
                'lastName' => $employee->last_name,
                'firstName' => $employee->first_name,
                'birthDate' => $employee->birth_date?->toDateString(),
                'nationalityIso2' => $employee->nationality,
                'employmentStartDate' => $employee->hire_date?->toDateString(),
                'weeklyContractHours' => round((float) $employee->fte_ratio * 42, 1),
                'suvaInsuredAnnualSalaryChf' => $this->amount($employee->getAttribute('base_annual_salary')),
            ],
            'accidentDetails' => [
                'accidentCategory' => 'BU',
                'occurrenceDateTime' => $when->toIso8601String(),
                'lastWorkedDateTime' => $when->toIso8601String(),
                'workplaceLocation' => [
                    'siteCode' => $incident->location_or_site_id,
                    'municipalityBfs' => $settings['municipality_bfs'] ?? null,
                    'canton' => $settings['canton'] ?? null,
                ],
                'activityPerformed' => $dynamics?->activityAtTimeOfAccident,
                'deviatingEventCode' => $dynamics?->deviatingEvent,
                'materialAgentCode' => $dynamics?->materialAgent,
                'injuredBodyPartCode' => $dynamics?->injuredBodyPartCode,
                'lesionNatureCode' => $dynamics?->lesionNatureCode,
                'ppeWornAtTimeOfAccident' => $ppe !== [],
                'verifiedPpeRecordIds' => $ppe,
                'expectedIncapacityDays' => $incident->prognosis_days,
                'treatingPhysicianOrHospital' => $settings['treating_physician'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inailPayload(WorkplaceIncidentReport $incident, Employee $employee, Tenant $tenant): array
    {
        $settings = $this->settings($tenant);
        $dynamics = $incident->injuryDynamics();
        $when = CarbonImmutable::parse($incident->incident_date)->setTimezone('Europe/Rome');
        $days = $incident->prognosis_days;
        $verification = $this->verification($incident, $employee);

        $declarationType = match (true) {
            $incident->incident_type === IncidentType::FatalOrSevere => 'INFORTUNIO_MORTALE',
            $days > 3 => 'DENUNCIA_INFORTUNIO',
            default => 'COMUNICAZIONE_1_3_GG',
        };

        return [
            'schemaVersion' => Jurisdiction::Italy->claimSchemaVersion(),
            'sourceModule' => 'MOD-099',
            'incidentReportId' => $incident->id,
            'protocolNumber' => $incident->protocol_number,
            'declarationType' => $declarationType,
            'employer' => [
                'codiceFiscale' => $tenant->fiscal_code,
                'codiceDittaInail' => $settings['inail_company_code'] ?? null,
                'patInail' => $settings['inail_pat'] ?? null,
                'voceTariffaInail' => $settings['inail_tariff_item'] ?? null,
                'ateco2007' => $settings['ateco2007'] ?? null,
            ],
            'worker' => [
                'employeeId' => $employee->id,
                'codiceFiscale' => $employee->tax_code,
                'lastName' => $employee->last_name,
                'firstName' => $employee->first_name,
                'gender' => $employee->gender,
                'birthDate' => $employee->birth_date?->toDateString(),
                'qualificaAssicurativaInail' => $settings['inail_default_qualification'] ?? null,
                'ccnlCode' => $settings['ccnl_code'] ?? null,
                'retribuzioneMediaGiornalieraEur' => ($annual = $this->amount($employee->getAttribute('base_annual_salary'))) === null ? null : round($annual / 365, 2),
            ],
            'eventDetails' => [
                'eventDateTime' => $when->toIso8601String(),
                'abandonedWorkDateTime' => $when->toIso8601String(),
                'isInItinere' => false,
                'municipalityIstatCode' => $settings['istat_municipality'] ?? null,
                'exactLocationDescription' => $incident->location_or_site_id,
                'esawCodes' => [
                    'workingEnvironment' => null,
                    'workingProcess' => null,
                    'specificPhysicalActivity' => $dynamics?->activityAtTimeOfAccident,
                    'deviation' => $dynamics?->deviatingEvent,
                    'contactModeOfInjury' => null,
                    'materialAgent' => $dynamics?->materialAgent,
                    'injuredBodyPart' => $dynamics?->injuredBodyPartCode,
                    'natureOfInjury' => $dynamics?->lesionNatureCode,
                ],
                'firstMedicalCertificateId' => null,
                'prognosisStartDate' => $when->toDateString(),
                'prognosisEndDate' => $when->addDays($days)->toDateString(),
                'prognosisDays' => $days,
                'witnesses' => $this->witnesses($incident),
                'mandatoryTrainingVerified' => $verification['training'],
                'ppeDeliveredAndSignedVerified' => $verification['ppe'],
            ],
        ];
    }

    private function amount(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(Tenant $tenant): array
    {
        $settings = $tenant->settings;

        return is_array($settings) && is_array($settings['hse'] ?? null) ? $settings['hse'] : [];
    }

    private function hazardClass(WorkplaceIncidentReport $incident): ?string
    {
        return SafetyRoleRequirementMatrix::query()->where('tenant_id', $incident->tenant_id)->where('is_active', true)
            ->whereNotNull('suva_hazard_class')
            ->when($incident->work_center_id !== null, fn ($query) => $query->where('work_center_id', $incident->work_center_id))
            ->value('suva_hazard_class');
    }

    /**
     * Ids of the signed PPE records the employee held on the day of the accident.
     *
     * @return list<string>
     */
    private function ppeWorn(WorkplaceIncidentReport $incident, Employee $employee): array
    {
        $day = CarbonImmutable::parse($incident->incident_date)->toDateString();

        return PpeDistributionRecord::query()
            ->where('tenant_id', $incident->tenant_id)->where('employee_id', $employee->id)
            ->where('delivery_status', PpeDeliveryStatus::DeliveredSigned->value)
            ->whereDate('handed_over_at', '<=', $day)
            ->where(fn ($query) => $query->whereNull('replacement_due_at')->orWhereDate('replacement_due_at', '>=', $day))
            ->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all();
    }

    /**
     * @return array{training: bool, ppe: bool}
     */
    private function verification(WorkplaceIncidentReport $incident, Employee $employee): array
    {
        $result = $this->gatekeeper->evaluate(new ClearanceRequest(
            (string) $incident->tenant_id, (string) $employee->id, ClearanceContext::ProductionWorkCenter,
            (string) ($incident->work_center_id ?? ''), CarbonImmutable::parse($incident->incident_date),
        ));
        $codes = array_map(static fn (BlockingReason $reason): BlockingReasonCode => $reason->code, $result->blockingReasons);

        return [
            'training' => array_filter($codes, static fn (BlockingReasonCode $code): bool => in_array($code, self::TRAINING_REASONS, true)) === [],
            'ppe' => ! in_array(BlockingReasonCode::PpeMissing, $codes, true),
        ];
    }

    /**
     * @return list<array{fullName: string, role: string|null}>
     */
    private function witnesses(WorkplaceIncidentReport $incident): array
    {
        $ids = $incident->witness_employee_ids_json ?? [];

        if ($ids === []) {
            return [];
        }

        return Employee::query()->where('tenant_id', $incident->tenant_id)->whereIn('id', $ids)->get()
            ->map(fn (Employee $witness): array => [
                'fullName' => trim($witness->first_name.' '.$witness->last_name),
                'role' => $witness->position_id === null ? null : DB::table('hr_positions')->where('id', $witness->position_id)->value('title'),
            ])->values()->all();
    }
}

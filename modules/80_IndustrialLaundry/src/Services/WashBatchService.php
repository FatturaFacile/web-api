<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IndustrialLaundry\Compliance\ComplianceStrategyRegistry;
use Modules\IndustrialLaundry\Compliance\WashBatchMeasurements;
use Modules\IndustrialLaundry\Domain\Enums\CertificationStatus;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\RabcRiskZone;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;
use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\IndustrialLaundry\Models\WashBatch;
use Modules\IndustrialLaundry\Models\WashProgram;
use Modules\IndustrialLaundry\Support\Money;

/**
 * UNI EN 14065 RABC wash batches: groups the soiled roll cages received into a batch of the wash line, traces which garments it
 * carried, and certifies it from what the line measured (the compliance strategies decide whether the disinfection was enough).
 */
final class WashBatchService
{
    public function __construct(
        private readonly WashProgramCatalog $catalog,
        private readonly ComplianceStrategyRegistry $compliance,
        private readonly GarmentLifecycleService $lifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $userId): WashBatch
    {
        $this->catalog->ensure($tenantId);
        $program = WashProgram::query()->where('code', (string) $data['program_code'])->where('is_active', true)->first() ?? throw new IndustrialLaundryException('program_unknown');
        $zone = RabcRiskZone::tryFrom((string) ($data['rabc_risk_zone'] ?? 'standard')) ?? throw new IndustrialLaundryException('risk_zone_invalid');
        $sessionIds = array_values(array_unique(array_map('strval', (array) ($data['scan_session_ids'] ?? []))));

        if ($sessionIds === []) {
            throw new IndustrialLaundryException('batch_sessions_required');
        }

        $sessions = ScanSession::query()->whereIn('id', $sessionIds)->get();

        if ($sessions->count() !== count($sessionIds) || $sessions->contains(fn (ScanSession $session): bool => $session->direction !== ScanDirection::InboundSoil)) {
            throw new IndustrialLaundryException('batch_sessions_invalid');
        }

        // A roll cage whose batch failed the hygiene control is washed again, in a new batch.
        $taken = DB::table('ilr_wash_batch_sessions as links')
            ->join('ilr_wash_batches as batches', 'batches.id', '=', 'links.wash_batch_id')
            ->whereIn('links.scan_session_id', $sessionIds)
            ->whereNull('links.deleted_at')
            ->whereNull('batches.deleted_at')
            ->where('batches.rabc_control_status', '!=', CertificationStatus::NonCompliant->value)
            ->exists();

        if ($taken) {
            throw new IndustrialLaundryException('session_already_batched');
        }

        $code = isset($data['batch_code']) && $data['batch_code'] !== '' ? (string) $data['batch_code'] : $this->nextCode();

        if (WashBatch::query()->withTrashed()->where('batch_code', $code)->exists()) {
            throw new IndustrialLaundryException('batch_code_taken', 422, ['code' => $code]);
        }

        return DB::transaction(function () use ($tenantId, $data, $userId, $program, $zone, $sessions, $code): WashBatch {
            $washedAt = isset($data['washed_at']) ? Carbon::parse((string) $data['washed_at']) : Carbon::now();
            $epcs = $sessions->flatMap(fn (ScanSession $session): array => (array) $session->accepted_epc_tags)->unique()->values()->all();
            $garments = Garment::query()->whereIn('epc_uid_rfid_hex', $epcs)->where('current_lifecycle_state', LifecycleState::SoiledReturned->value)->get();
            $weight = Money::weight(Money::sum($sessions->pluck('net_weight_kg')));

            $batch = WashBatch::query()->create([
                'tenant_id' => $tenantId,
                'batch_code' => $code,
                'disinfection_thermal_chem_program_code' => $program->code,
                'rabc_risk_zone' => $zone->value,
                'rabc_control_status' => CertificationStatus::Pending->value,
                'target_temperature_c' => $program->target_temperature_c,
                'target_hold_minutes' => $program->min_hold_minutes,
                'target_chemical_dosing_ml_per_kg' => $program->min_chemical_dosing_ml_per_kg,
                'load_net_weight_kg' => $weight,
                'garments_count' => $garments->count(),
                'operator_name' => $data['operator_name'] ?? null,
                'washed_at' => $washedAt,
                'created_by' => $userId,
            ]);

            $now = Carbon::now();

            foreach ($sessions as $session) {
                DB::table('ilr_wash_batch_sessions')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'wash_batch_id' => $batch->id, 'scan_session_id' => $session->id, 'created_at' => $now, 'updated_at' => $now]);
            }

            foreach ($garments as $garment) {
                DB::table('ilr_wash_batch_garments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'wash_batch_id' => $batch->id, 'garment_id' => $garment->id, 'created_at' => $now, 'updated_at' => $now]);
                $this->lifecycle->move($garment, LifecycleState::InWashTunnel, GarmentEventType::WashStarted, $washedAt, ['note' => $code, 'userId' => $userId]);
            }

            return $batch->load(['sessions', 'garments']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function certify(WashBatch $batch, array $data, ?string $userId): WashBatch
    {
        if ($batch->rabc_control_status === CertificationStatus::Compliant) {
            throw new IndustrialLaundryException('batch_already_certified');
        }

        if ($batch->rabc_control_status === CertificationStatus::NonCompliant) {
            throw new IndustrialLaundryException('batch_not_pending');
        }

        $program = WashProgram::query()->where('code', $batch->disinfection_thermal_chem_program_code)->firstOrFail();
        $measurements = new WashBatchMeasurements(
            $program,
            $batch->rabc_risk_zone,
            isset($data['measured_temperature_c']) ? Money::round($data['measured_temperature_c'], 2) : null,
            isset($data['measured_hold_minutes']) ? (int) $data['measured_hold_minutes'] : null,
            isset($data['measured_chemical_dosing_ml_per_kg']) ? Money::weight($data['measured_chemical_dosing_ml_per_kg']) : null,
            isset($data['microbiological_cfu_per_25cm2']) ? Money::round($data['microbiological_cfu_per_25cm2'], 2) : null,
        );
        $findings = $this->compliance->batchFindings($measurements);
        $compliant = ! ComplianceStrategyRegistry::blocks($findings);

        return DB::transaction(function () use ($batch, $data, $userId, $measurements, $findings, $compliant): WashBatch {
            $batch->forceFill([
                'measured_temperature_c' => $measurements->temperatureC,
                'measured_hold_minutes' => $measurements->holdMinutes,
                'measured_chemical_dosing_ml_per_kg' => $measurements->chemicalDosingMlPerKg,
                'microbiological_cfu_per_25cm2' => $measurements->cfuPer25Cm2,
                'verifier_name' => $data['verifier_name'] ?? null,
                'verified_by_user_id' => $userId,
                'rabc_control_status' => ($compliant ? CertificationStatus::Compliant : CertificationStatus::NonCompliant)->value,
                'violations' => array_map(static fn ($finding): array => $finding->toArray(), $findings),
                'certified_at' => $compliant ? Carbon::now() : null,
                'updated_by' => $userId,
            ])->save();

            if (! $compliant) {
                $this->returnToSoiled($batch, $userId);
            }

            return $batch->load(['sessions', 'garments']);
        });
    }

    /**
     * A batch that failed the hygiene control is washed again: its garments go back to the soiled state.
     */
    private function returnToSoiled(WashBatch $batch, ?string $userId): void
    {
        foreach ($batch->garments()->where('current_lifecycle_state', LifecycleState::InWashTunnel->value)->get() as $garment) {
            $this->lifecycle->move($garment, LifecycleState::SoiledReturned, GarmentEventType::ReceivedSoiled, Carbon::now(), ['note' => $batch->batch_code, 'userId' => $userId]);
        }
    }

    private function nextCode(): string
    {
        $prefix = 'RABC-'.Carbon::now()->format('Ymd').'-';
        $count = WashBatch::query()->withTrashed()->where('batch_code', 'like', $prefix.'%')->count();

        return $prefix.str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
    }
}

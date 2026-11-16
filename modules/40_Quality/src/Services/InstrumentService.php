<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\QmsCalibration;
use Modules\Quality\Models\QmsInstrument;
use Modules\Quality\Models\QmsSpcCharacteristic;
use Modules\Quality\Models\QmsSpcSample;

/**
 * Measuring instruments under calibration control. Recording a calibration moves the due date forward by the
 * calibration interval (or the date on the certificate). An out-of-tolerance result puts the instrument in
 * quarantine, raises a major non-conformity and makes every SPC sample measured with it since the previous
 * calibration suspect (see impact()).
 */
final readonly class InstrumentService
{
    public function __construct(private QualityLedger $ledger, private NonconformityService $nonconformities) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): QmsInstrument
    {
        $this->assertCodeFree($tenantId, (string) $data['code'], null);

        return QmsInstrument::create([...$this->fields($data), 'tenant_id' => $tenantId, 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QmsInstrument $instrument, array $data): QmsInstrument
    {
        if (isset($data['code'])) {
            $this->assertCodeFree($instrument->tenant_id, (string) $data['code'], $instrument->id);
        }
        $instrument->update($this->fields($data));

        return $instrument->refresh();
    }

    public function retire(QmsInstrument $instrument): QmsInstrument
    {
        $instrument->update(['status' => 'retired']);
        $this->ledger->record('QMS_INSTRUMENT_RETIRED', $instrument);

        return $instrument->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordCalibration(QmsInstrument $instrument, array $data, ?string $actorId): QmsCalibration
    {
        if ($instrument->status === 'retired') {
            throw ValidationException::withMessages(['instrument_id' => [__('quality_errors.instrument.retired')]]);
        }
        $calibratedOn = Carbon::parse((string) $data['calibrated_on'])->startOfDay();
        $nextDue = isset($data['next_due_on']) ? Carbon::parse((string) $data['next_due_on'])->startOfDay() : $calibratedOn->copy()->addDays($instrument->calibration_interval_days);
        if ($nextDue->lte($calibratedOn)) {
            throw ValidationException::withMessages(['next_due_on' => [__('quality_errors.instrument.due_before_calibration')]]);
        }
        if ($data['result'] === 'pass' && $instrument->tolerance !== null && isset($data['as_found_error']) && abs((float) $data['as_found_error']) > (float) $instrument->tolerance) {
            throw ValidationException::withMessages(['result' => [__('quality_errors.instrument.error_exceeds_tolerance')]]);
        }

        return DB::transaction(function () use ($instrument, $data, $calibratedOn, $nextDue, $actorId): QmsCalibration {
            $calibration = QmsCalibration::create([
                'tenant_id' => $instrument->tenant_id,
                'instrument_id' => $instrument->id,
                'calibrated_on' => $calibratedOn->toDateString(),
                'next_due_on' => $nextDue->toDateString(),
                'result' => $data['result'],
                'as_found_error' => $data['as_found_error'] ?? null,
                'as_left_error' => $data['as_left_error'] ?? null,
                'laboratory' => $data['laboratory'] ?? null,
                'certificate_number' => $data['certificate_number'] ?? null,
                'certificate_document_id' => $data['certificate_document_id'] ?? null,
                'performed_by' => $actorId,
                'notes' => $data['notes'] ?? null,
            ]);

            $isLatest = $instrument->last_calibrated_on === null || $calibratedOn->gte($instrument->last_calibrated_on);
            if ($isLatest) {
                $instrument->update([
                    'last_calibrated_on' => $calibratedOn->toDateString(),
                    'next_due_on' => $nextDue->toDateString(),
                    'status' => $calibration->result === 'out_of_tolerance' ? 'quarantined' : 'active',
                ]);
            }

            if ($calibration->result === 'out_of_tolerance') {
                $nonconformity = $this->nonconformities->create($instrument->tenant_id, [
                    'title' => __('quality_errors.instrument.nonconformity_title', ['code' => $instrument->code]),
                    'description' => __('quality_errors.instrument.nonconformity_description', ['code' => $instrument->code, 'date' => $calibratedOn->toDateString()]),
                    'source' => 'internal',
                    'severity' => 'major',
                    'detected_at' => $calibratedOn->toDateString(),
                ], $actorId);
                $calibration->update(['nonconformity_id' => $nonconformity->id]);
            }

            $this->ledger->record('QMS_INSTRUMENT_CALIBRATED', $calibration, ['result' => $calibration->result, 'next_due_on' => $nextDue->toDateString()]);

            return $calibration->refresh();
        });
    }

    /**
     * SPC samples measured with the instrument since the previous calibration up to this one: the results to review
     * when the calibration is out of tolerance.
     *
     * @return array{from: string|null, to: string, sample_count: int, characteristics: list<array<string, mixed>>}
     */
    public function impact(QmsCalibration $calibration): array
    {
        $previous = QmsCalibration::query()
            ->where('instrument_id', $calibration->instrument_id)
            ->where('calibrated_on', '<', $calibration->calibrated_on->toDateString())
            ->orderByDesc('calibrated_on')->first();

        $samples = QmsSpcSample::query()
            ->where('instrument_id', $calibration->instrument_id)
            ->when($previous !== null, static fn ($query) => $query->where('sampled_at', '>', $previous->calibrated_on->copy()->endOfDay()))
            ->where('sampled_at', '<=', $calibration->calibrated_on->copy()->endOfDay())
            ->orderBy('sampled_at')->get();

        $names = QmsSpcCharacteristic::query()->whereIn('id', $samples->pluck('characteristic_id')->unique())->get()->keyBy('id');
        $characteristics = $samples->groupBy('characteristic_id')->map(static fn ($group, $id): array => [
            'characteristic_id' => (string) $id,
            'code' => $names[$id]->code ?? null,
            'name' => $names[$id]->name ?? null,
            'sample_count' => $group->count(),
            'lots' => $group->pluck('lot')->filter()->unique()->values()->all(),
        ])->values()->all();

        return [
            'from' => $previous?->calibrated_on->toDateString(),
            'to' => $calibration->calibrated_on->toDateString(),
            'sample_count' => $samples->count(),
            'characteristics' => $characteristics,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(['code', 'name', 'serial_number', 'manufacturer', 'location', 'unit', 'range_min', 'range_max', 'tolerance', 'calibration_interval_days', 'notes']));
    }

    private function assertCodeFree(string $tenantId, string $code, ?string $ignoreId): void
    {
        $taken = QmsInstrument::query()->where('tenant_id', $tenantId)->where('code', $code)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => [__('quality_errors.instrument.code_exists')]]);
        }
    }
}

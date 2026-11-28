<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Beekeeping\Events\VarroaTreatmentRegistered;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\VarroaTreatment;

/**
 * The electronic veterinary treatment register (Italian BDA / VETINFO, Swiss TAMV): every entry computes the end of its withdrawal period.
 */
final class VarroaRegisterService
{
    public function __construct(private readonly WithdrawalGuard $guard) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): VarroaTreatment
    {
        $defaults = (array) config('beekeeping.treatment_products.'.$data['product'], []);
        $wholeApiary = (bool) ($data['whole_apiary'] ?? true);
        /** @var list<string> $hiveIds */
        $hiveIds = array_values(array_unique((array) ($data['hive_ids'] ?? [])));

        if (! $wholeApiary) {
            if ($hiveIds === []) {
                throw new BeekeepingException('Select the treated hives.', 'treatment_hives_required');
            }

            if (Hive::query()->where('apiary_id', $data['apiary_id'])->whereIn('id', $hiveIds)->count() !== count($hiveIds)) {
                throw new BeekeepingException('A treated hive does not belong to the apiary.', 'treatment_hive_outside_apiary');
            }
        }

        return DB::transaction(function () use ($data, $defaults, $wholeApiary, $hiveIds): VarroaTreatment {
            $treatment = new VarroaTreatment([
                'apiary_id' => $data['apiary_id'],
                'whole_apiary' => $wholeApiary,
                'hive_ids' => $wholeApiary ? null : $hiveIds,
                'treatment_date' => $data['treatment_date'],
                'treatment_end_date' => $data['treatment_end_date'] ?? null,
                'product' => $data['product'],
                'active_substance' => $data['active_substance'] ?? ($defaults['substance'] ?? $data['product']),
                'commercial_name' => $data['commercial_name'] ?? null,
                'drug_batch' => $data['drug_batch'] ?? null,
                'prescription_ref' => $data['prescription_ref'] ?? null,
                'veterinarian' => $data['veterinarian'] ?? null,
                'dosage' => $data['dosage'] ?? null,
                'application_method' => $data['application_method'] ?? null,
                'withdrawal_days' => (int) ($data['withdrawal_days'] ?? $defaults['withdrawal_days'] ?? 0),
                'notes' => $data['notes'] ?? null,
            ]);
            $treatment->save();

            event(new VarroaTreatmentRegistered($treatment->id, $treatment->apiary_id, $treatment->withdrawal_end_date->toDateString()));

            return $treatment;
        });
    }

    /**
     * Locks of an apiary today: hives that cannot take honey supers, with the end of their withdrawal.
     *
     * @return list<array{hive_id: string, hive_code: string, until: string, product: string, days_left: int}>
     */
    public function locksOfApiary(string $apiaryId, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::today();
        $locked = $this->guard->lockedHives($apiaryId, $date);
        $codes = Hive::query()->whereIn('id', array_keys($locked))->pluck('code', 'id');
        $rows = [];

        foreach ($locked as $hiveId => $treatment) {
            $rows[] = [
                'hive_id' => $hiveId,
                'hive_code' => (string) ($codes[$hiveId] ?? ''),
                'until' => $treatment->withdrawal_end_date->toDateString(),
                'product' => $treatment->product,
                'days_left' => $treatment->window()->daysLeft($date),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['hive_code'], $b['hive_code']));

        return $rows;
    }
}

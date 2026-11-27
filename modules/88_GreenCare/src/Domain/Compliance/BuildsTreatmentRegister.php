<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Compliance;

use Illuminate\Support\Collection;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;

/**
 * The columns of the register of the phytosanitary treatments, shared by the strategies of the jurisdictions.
 */
trait BuildsTreatmentRegister
{
    /**
     * @param  Collection<int, PhytosanitaryTreatmentLog>  $logs
     * @return array{title: string, columns: list<string>, rows: list<list<string>>}
     */
    private function buildRegister(Collection $logs, string $titleKey): array
    {
        $columns = [];

        foreach (['date', 'site', 'crop', 'area', 'pest', 'product', 'registration', 'ingredient', 'dose', 'water', 'operator', 'license', 'reentry'] as $column) {
            $columns[] = __('green_care.register.columns.'.$column);
        }

        $logs->loadMissing(['site', 'operator', 'license']);

        $rows = $logs->map(static fn (PhytosanitaryTreatmentLog $log): array => [
            $log->treatment_date->format('d/m/Y'),
            (string) ($log->site->name ?? '-'),
            $log->treated_crop_or_surface,
            number_format((float) $log->treated_area_sqm, 2, '.', ''),
            $log->target_pest_or_weed,
            $log->commercial_product_name,
            $log->registration_number,
            $log->active_ingredient,
            rtrim(rtrim(number_format((float) $log->dosage_used, 4, '.', ''), '0'), '.').' '.$log->dosage_unit->value,
            $log->water_volume_liters === null ? '-' : number_format((float) $log->water_volume_liters, 2, '.', ''),
            (string) ($log->operator->name ?? '-'),
            $log->license === null ? '-' : $log->license->license_type->value.' '.$log->license->license_number,
            (string) $log->re_entry_interval_hours.' h',
        ])->values()->all();

        return ['title' => (string) __($titleKey), 'columns' => $columns, 'rows' => $rows];
    }
}

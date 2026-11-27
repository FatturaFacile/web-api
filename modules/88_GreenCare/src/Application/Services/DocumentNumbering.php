<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\GreenCare\Models\GreenWasteSlip;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

/**
 * Sequential numbers of the module documents: contracts (GC-2026-0001) and internal waste slips (GW-2026-0001).
 */
final class DocumentNumbering
{
    public function nextContractNumber(Carbon $date): string
    {
        return $this->next(SeasonalMaintenanceContract::class, 'contract_number', 'GC', $date);
    }

    public function nextWasteSlipNumber(Carbon $date): string
    {
        return $this->next(GreenWasteSlip::class, 'slip_number', 'GW', $date);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function next(string $model, string $column, string $prefix, Carbon $date): string
    {
        $stem = $prefix.'-'.$date->format('Y').'-';
        $last = $model::query()->withTrashed()->where($column, 'like', $stem.'%')->orderByDesc($column)->value($column);
        $sequence = is_string($last) ? ((int) substr($last, strlen($stem))) + 1 : 1;

        return $stem.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}

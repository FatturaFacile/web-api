<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsTariffBand;
use Modules\Transportation\Models\TmsTariffTable;

/**
 * Tariff tables: the price list of a carrier for a period. Deleting a table deletes its bands with it.
 */
final class TariffTableService extends TransportationCrudService
{
    protected function modelClass(): string
    {
        return TmsTariffTable::class;
    }

    protected function prepare(string $tenantId, array $data, ?Model $existing): array
    {
        if (isset($data['currency'])) {
            $data['currency'] = Str::upper((string) $data['currency']);
        }

        return $data;
    }

    public function delete(Model $record): void
    {
        DB::transaction(function () use ($record): void {
            TmsTariffBand::query()->where('tariff_table_id', $record->getKey())->delete();
            parent::delete($record);
        });
    }
}

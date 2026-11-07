<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Modules\ConstructionTrades\Models\PriceItem;
use Modules\ConstructionTrades\Models\PriceList;
use SplFileObject;

/**
 * Imports positions into a price list from a neutral CSV layout. The layout is the structure for any catalogue the
 * company is licensed to use (CRB NPK, ASIP/SMGV price data): the module neither ships nor converts proprietary data, so
 * a licensed catalogue has to be exported to this layout by the licence holder.
 *
 * Columns, by header name: position_code, unit, unit_price (required); parent_code, labour_minutes, material_cost,
 * machine_cost, description_de, description_fr, description_it, description_en (optional). The separator is ";" or ",".
 */
final class PriceListImporter
{
    /** @var list<string> */
    public const array RequiredColumns = ['position_code', 'unit', 'unit_price'];

    private const int MaxRows = 50000;

    /**
     * @return array{created: int, updated: int, errors: list<array{line: int, message: string}>}
     */
    public function import(PriceList $list, string $path): array
    {
        $file = new SplFileObject($path);
        $firstLine = (string) $file->fgets();
        $separator = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $header = array_map(static fn (?string $name): string => strtolower(trim((string) $name, " \t\n\r\0\x0B\u{FEFF}")), str_getcsv($firstLine, $separator, '"', ''));
        $missing = array_diff(self::RequiredColumns, $header);

        if ($missing !== []) {
            throw new BusinessRuleException('Missing columns: '.implode(', ', $missing).'.');
        }

        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        $line = 1;

        DB::transaction(function () use ($file, $separator, $header, $list, &$result, &$line): void {
            while (! $file->eof()) {
                $raw = $file->fgetcsv($separator, '"', '');
                $line++;

                if ($raw === false || $raw === [null] || $raw === []) {
                    continue;
                }

                if ($line > self::MaxRows + 1) {
                    throw new BusinessRuleException('The file exceeds '.self::MaxRows.' rows.');
                }

                $row = array_combine($header, array_pad(array_slice($raw, 0, count($header)), count($header), null));
                $error = $this->rowError($row, $list);

                if ($error !== null) {
                    $result['errors'][] = ['line' => $line, 'message' => $error];

                    continue;
                }

                $item = PriceItem::query()->firstOrNew(['price_list_id' => $list->id, 'position_code' => trim((string) $row['position_code'])]);
                $result[$item->exists ? 'updated' : 'created']++;
                $item->fill([
                    'parent_code' => $this->nullable($row['parent_code'] ?? null),
                    'unit' => trim((string) $row['unit']),
                    'unit_price' => $this->decimal($row['unit_price']),
                    'labour_minutes' => $this->decimalOrNull($row['labour_minutes'] ?? null),
                    'material_cost' => $this->decimalOrNull($row['material_cost'] ?? null),
                    'machine_cost' => $this->decimalOrNull($row['machine_cost'] ?? null),
                    'descriptions' => $this->descriptions($row, $list),
                ]);
                $item->save();
            }
        });

        return $result;
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function rowError(array $row, PriceList $list): ?string
    {
        $code = trim((string) $row['position_code']);

        if ($code === '' || mb_strlen($code) > 40) {
            return 'position_code is empty or longer than 40 characters.';
        }

        if (trim((string) $row['unit']) === '' || mb_strlen(trim((string) $row['unit'])) > 10) {
            return 'unit is empty or longer than 10 characters.';
        }

        foreach (['unit_price', 'labour_minutes', 'material_cost', 'machine_cost'] as $column) {
            $value = $this->nullable($row[$column] ?? null);

            if (($column === 'unit_price' || $value !== null) && preg_match('/^\d{1,11}([.,]\d{1,4})?$/', (string) $value) !== 1) {
                return "{$column} is not a non-negative amount.";
            }
        }

        if ($this->descriptions($row, $list) === []) {
            return 'No description in the languages of the price list.';
        }

        return null;
    }

    /**
     * @param  array<string, ?string>  $row
     * @return array<string, string>
     */
    private function descriptions(array $row, PriceList $list): array
    {
        $descriptions = [];

        foreach ($list->languages as $language) {
            $text = $this->nullable($row["description_{$language}"] ?? null);

            if ($text !== null) {
                $descriptions[$language] = mb_substr($text, 0, 4000);
            }
        }

        return $descriptions;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function decimal(?string $value): string
    {
        return str_replace(',', '.', trim((string) $value));
    }

    private function decimalOrNull(?string $value): ?string
    {
        return $this->nullable($value) === null ? null : $this->decimal($value);
    }
}

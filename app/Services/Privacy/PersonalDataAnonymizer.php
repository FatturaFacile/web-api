<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anonymises the personal columns of the rows of one registry entry, one row at a time, so that the pseudonym of an
 * e-mail address is unique per row. Rows are never deleted. Columns that cannot take a neutral value (NOT NULL dates,
 * numbers, JSON) are left untouched and reported, so nobody believes they were erased.
 */
final class PersonalDataAnonymizer
{
    public const string Redacted = 'REDACTED';

    /**
     * Pseudonym used in place of an e-mail address: unique per row, undeliverable (reserved .invalid TLD, RFC 2606).
     */
    public static function pseudonymEmail(string $rowId): string
    {
        return "anonymized-{$rowId}@redacted.invalid";
    }

    /**
     * @param  Closure(Builder): void  $scope  narrows the rows of the table (tenant, subject, age...)
     * @return array{rows: int, columns: list<string>, skipped_columns: list<string>}
     */
    public function anonymize(PersonalDataEntry $entry, Closure $scope): array
    {
        $columns = $this->columnMeta($entry->table);
        $targets = [];
        $skipped = [];

        foreach ($entry->personalColumns as $name) {
            $meta = $columns[$name] ?? null;
            if ($meta === null) {
                continue;
            }

            if ($meta['nullable'] || $this->isTextual($meta['type'])) {
                $targets[$name] = $meta;
            } else {
                $skipped[] = $name;
            }
        }

        $now = CarbonImmutable::now();
        $stamp = [];
        foreach (['anonymized_at', 'processing_restricted_at'] as $marker) {
            if (isset($columns[$marker])) {
                $stamp[$marker] = $now;
            }
        }

        if (isset($columns['updated_at'])) {
            $stamp['updated_at'] = $now;
        }

        $rows = 0;
        $query = DB::table($entry->table);
        $scope($query);
        $query->chunkById(200, function ($chunk) use ($entry, $targets, $stamp, &$rows): void {
            foreach ($chunk as $row) {
                $values = [];
                foreach ($targets as $name => $meta) {
                    $current = $row->{$name} ?? null;
                    if ($current === null) {
                        continue;
                    }

                    $values[$name] = $this->neutralValue($name, $meta, (string) $row->id);
                }

                if ($values === []) {
                    continue;
                }

                DB::table($entry->table)->where('id', $row->id)->update($values + $stamp);
                $rows++;
            }
        });

        return ['rows' => $rows, 'columns' => array_keys($targets), 'skipped_columns' => $skipped];
    }

    /**
     * Rows of the entry that still hold personal data: at least one textual personal column is neither empty nor
     * already a pseudonym. Keeps retention runs idempotent.
     */
    public function scopeToUnanonymised(PersonalDataEntry $entry, Builder $query): void
    {
        $columns = $this->columnMeta($entry->table);
        $textual = array_values(array_filter($entry->personalColumns, fn (string $name): bool => isset($columns[$name]) && $this->isTextual($columns[$name]['type'])));
        if ($textual === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $inner) use ($textual): void {
            foreach ($textual as $name) {
                $inner->orWhere(function (Builder $one) use ($name): void {
                    $one->whereNotNull($name)
                        ->where($name, '!=', self::Redacted)
                        ->where($name, 'not like', 'anonymized-%@redacted.invalid');
                });
            }
        });
    }

    /**
     * @return array<string, array{type: string, nullable: bool, length: int|null}>
     */
    private function columnMeta(string $table): array
    {
        $meta = [];
        foreach (Schema::getColumns($table) as $column) {
            $typeName = strtolower($column['type_name']);
            $length = null;
            if (preg_match('/\((\d+)\)/', $column['type'], $found) === 1) {
                $length = (int) $found[1];
            }

            $meta[(string) $column['name']] = ['type' => $typeName, 'nullable' => (bool) $column['nullable'], 'length' => $length];
        }

        return $meta;
    }

    private function isTextual(string $type): bool
    {
        return in_array($type, ['varchar', 'char', 'bpchar', 'text', 'string'], true);
    }

    /**
     * @param  array{type: string, nullable: bool, length: int|null}  $meta
     */
    private function neutralValue(string $name, array $meta, string $rowId): ?string
    {
        if (str_contains($name, 'email')) {
            $pseudonym = self::pseudonymEmail($rowId);
            if ($meta['length'] === null || $meta['length'] >= strlen($pseudonym)) {
                return $pseudonym;
            }
        }

        if ($meta['nullable']) {
            return null;
        }

        return $meta['length'] !== null ? substr(self::Redacted, 0, $meta['length']) : self::Redacted;
    }
}

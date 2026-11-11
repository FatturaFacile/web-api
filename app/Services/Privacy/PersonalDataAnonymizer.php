<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Anonymises the personal columns of the rows of one registry entry, one row at a time, so that the pseudonym of an
 * e-mail address is unique per row. Rows are never deleted. Columns that cannot take a neutral value (NOT NULL dates,
 * numbers, JSON) are left untouched and reported, so nobody believes they were erased.
 *
 * Many personal columns are encrypted at rest by the cast of their model. The neutral value is written through that
 * cast (never as plaintext, which the model could no longer decrypt), and whether a row is already anonymised is
 * judged on the decrypted value.
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
     * @param  list<string>|null  $onlyColumns  anonymise only these personal columns (null: all of the entry)
     * @return array{rows: int, columns: list<string>, skipped_columns: list<string>}
     */
    public function anonymize(PersonalDataEntry $entry, Closure $scope, ?array $onlyColumns = null): array
    {
        $targets = $this->targets($entry, $onlyColumns);
        $skipped = $targets['skipped'];
        $targets = $targets['targets'];
        $columns = $this->columnMeta($entry->table);
        $model = $this->modelOf($entry);

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
        $query->chunkById(200, function ($chunk) use ($entry, $targets, $stamp, $model, &$rows): void {
            foreach ($chunk as $row) {
                $values = [];
                foreach ($targets as $name => $meta) {
                    $current = $row->{$name} ?? null;
                    if ($current === null) {
                        continue;
                    }

                    $neutral = $this->neutralValue($name, $meta, (string) $row->id);
                    if ($this->plain($model, $name, $current) === $neutral) {
                        continue;
                    }

                    $values[$name] = $this->stored($model, $name, $neutral);
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
     * Ids of the rows of the scope that still hold personal data (at least one personal column is neither empty nor
     * already neutral), judged on the decrypted values. Keeps retention runs idempotent.
     *
     * @param  Closure(Builder): void  $scope
     * @return list<string>
     */
    public function pendingIds(PersonalDataEntry $entry, Closure $scope, ?int $limit = null): array
    {
        $targets = $this->targets($entry, null)['targets'];
        $model = $this->modelOf($entry);
        $ids = [];

        $query = DB::table($entry->table);
        $scope($query);
        $query->chunkById(200, function ($chunk) use ($targets, $model, $limit, &$ids): bool {
            foreach ($chunk as $row) {
                foreach ($targets as $name => $meta) {
                    $current = $row->{$name} ?? null;
                    if ($current !== null && $this->plain($model, $name, $current) !== $this->neutralValue($name, $meta, (string) $row->id)) {
                        $ids[] = (string) $row->id;

                        break;
                    }
                }

                if ($limit !== null && count($ids) >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $ids;
    }

    /**
     * @return array{targets: array<string, array{type: string, nullable: bool, length: int|null}>, skipped: list<string>}
     */
    private function targets(PersonalDataEntry $entry, ?array $onlyColumns): array
    {
        $columns = $this->columnMeta($entry->table);
        $targets = [];
        $skipped = [];

        foreach ($entry->personalColumns as $name) {
            if ($onlyColumns !== null && ! in_array($name, $onlyColumns, true)) {
                continue;
            }

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

        return ['targets' => $targets, 'skipped' => $skipped];
    }

    /**
     * An instance of the first model of the entry, used only to run the casts of its attributes.
     */
    private function modelOf(PersonalDataEntry $entry): ?Model
    {
        foreach ($entry->models as $class) {
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                return new $class;
            }
        }

        return null;
    }

    private function isEncrypted(?Model $model, string $name): bool
    {
        $cast = $model?->getCasts()[$name] ?? null;

        return is_string($cast) && str_starts_with($cast, 'encrypted');
    }

    /**
     * The value to store for a neutral value: encrypted through the cast of the model when the column is encrypted.
     */
    private function stored(?Model $model, string $name, ?string $neutral): mixed
    {
        if ($neutral === null || ! $this->isEncrypted($model, $name)) {
            return $neutral;
        }

        $cast = (string) $model?->getCasts()[$name];
        $clone = new ($model::class);
        $clone->setAttribute($name, str_contains($cast, ':') && $cast !== 'encrypted' ? [] : $neutral);

        return $clone->getAttributes()[$name];
    }

    /**
     * The plain value of a stored one: decrypted through the cast of the model when the column is encrypted.
     */
    private function plain(?Model $model, string $name, mixed $stored): mixed
    {
        if (! $this->isEncrypted($model, $name)) {
            return $stored;
        }

        try {
            $clone = new ($model::class);
            $clone->setRawAttributes([$name => $stored]);
            $value = $clone->getAttribute($name);

            return is_scalar($value) || $value === null ? $value : json_encode($value);
        } catch (Throwable) {
            return $stored;
        }
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

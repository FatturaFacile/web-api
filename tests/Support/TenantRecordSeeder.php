<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Builds one row of any model for a tenant, for the generic tenant isolation tests: through the model factory when it
 * works, otherwise by a synthesised insert of the required columns only (foreign keys not enforced for the
 * transaction, which needs a database role allowed to change session_replication_role).
 */
final class TenantRecordSeeder
{
    public static function tenant(string $name): Tenant
    {
        return Tenant::create([
            'name' => $name.' '.Str::random(5),
            'legal_name' => $name.' SA',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]);
    }

    /**
     * Creates one row of the model in the tenant, inside a savepoint. Returns its key, or null when the row could not
     * be built; the reason is stored in $reasons.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, string>  $reasons
     */
    public static function seed(string $modelClass, Tenant $tenant, bool $hasFactory, array &$reasons = []): ?string
    {
        $context = app(TenantContext::class);

        try {
            return DB::transaction(static function () use ($modelClass, $tenant, $context, $hasFactory): string {
                $context->setTenant($tenant);

                if ($hasFactory) {
                    try {
                        return DB::transaction(static function () use ($modelClass, $tenant): string {
                            $record = $modelClass::factory()->create(['tenant_id' => $tenant->id]);

                            if (! $record instanceof $modelClass) {
                                throw new RuntimeException('The factory builds another model.');
                            }

                            $stored = DB::table($record->getTable())->where($record->getKeyName(), $record->getKey())->first();

                            if ($stored === null || (string) ($stored->tenant_id ?? '') !== (string) $tenant->id) {
                                throw new RuntimeException('Not stored in the tenant.');
                            }

                            return (string) $record->getKey();
                        });
                    } catch (Throwable) {
                        // Falls through to the synthesised row.
                    }
                }

                return self::insert($modelClass, $tenant);
            });
        } catch (Throwable $exception) {
            $reasons[$modelClass] = substr($exception->getMessage(), 0, 160);

            return null;
        } finally {
            $context->clear();
        }
    }

    /**
     * Stores one row of the model with a null tenant_id by a synthesised insert (the model would refuse it), to prove
     * what the tenant policy shows and hides. Returns its key, or null when the row could not be built.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function seedNullTenantRow(string $modelClass): ?string
    {
        try {
            return DB::transaction(static fn (): string => self::insert($modelClass, null));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function insert(string $modelClass, ?Tenant $tenant, bool $enforceForeignKeys = false): string
    {
        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $key = $model->getKeyName();
        $values = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];

            if ($name === 'tenant_id') {
                $values[$name] = $tenant?->id;

                continue;
            }

            if ($column['nullable'] || ($column['default'] !== null && $name !== $key) || $column['auto_increment']) {
                continue;
            }

            $type = $column['type_name'];
            $values[$name] = match (true) {
                $type === 'uuid' => (string) Str::uuid7(),
                str_contains($type, 'int') => 1,
                in_array($type, ['numeric', 'float4', 'float8', 'decimal'], true) => 1,
                $type === 'bool' => false,
                $type === 'date' => '2026-01-01',
                str_starts_with($type, 'timestamp') => now()->toDateTimeString(),
                str_starts_with($type, 'time') => '10:00:00',
                in_array($type, ['json', 'jsonb'], true) => '{}',
                in_array($type, ['varchar', 'text', 'bpchar'], true) => $name === $key ? (string) Str::uuid7() : 'x',
                default => throw new RuntimeException("No value for {$table}.{$name} ({$type})"),
            };
        }

        if (! isset($values[$key]) && ! $model->getIncrementing()) {
            $values[$key] = (string) Str::uuid7();
        }

        if (! $enforceForeignKeys) {
            DB::statement('SET LOCAL session_replication_role = replica');
        }

        if ($model->getIncrementing() && ! isset($values[$key])) {
            return (string) DB::table($table)->insertGetId($values, $key);
        }

        DB::table($table)->insert($values);

        return (string) $values[$key];
    }
}

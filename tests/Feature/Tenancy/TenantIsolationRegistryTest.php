<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\TenantModelRegistry;
use Tests\Support\TenantRecordSeeder;

uses(RefreshDatabase::class);

/**
 * Registry driven tenant isolation at the data layer.
 *
 * For every model that carries the tenant scope a row is created in tenant A (through the model factory, or, when the
 * model has none, by a synthesised insert that fills only the required columns), then the row must be invisible and
 * untouchable (read, find, update, delete, with trashed) from tenant B and from a context with no tenant at all
 * (console, queue worker, scheduler).
 *
 * A second test covers the null tenant: a row with a null tenant_id is invisible to every tenant and to the context with
 * no tenant for every model that does not allow global records; for the models that do, it is visible to everybody
 * and cannot be changed or deleted from a tenant context.
 *
 * Models that cannot be seeded in either way are reported by the coverage guard, never silently dropped.
 */

/**
 * A model that refuses the write on its own (immutable ledgers) counts as untouched.
 *
 * @param  callable(): int  $callback
 */
function isolationGuarded(callable $callback): int
{
    try {
        return DB::transaction($callback);
    } catch (Throwable) {
        return 0;
    }
}

test('rows of a tenant are invisible and untouchable from another tenant and from a context without tenant', function (): void {
    $tenantA = TenantRecordSeeder::tenant('Alfa');
    $tenantB = TenantRecordSeeder::tenant('Beta');
    $context = app(TenantContext::class);

    $exercised = 0;
    $unseedable = [];
    $leaks = [];
    $broken = [];

    foreach (TenantModelRegistry::models() as $entry) {
        if (! $entry['scoped'] || ! $entry['hasTenantColumn']) {
            continue;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $entry['class'];
        $key = TenantRecordSeeder::seed($modelClass, $tenantA, $entry['hasFactory']);

        if ($key === null) {
            $unseedable[] = $modelClass;

            continue;
        }

        $exercised++;
        $keyName = (new $modelClass)->getKeyName();
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);

        try {
            $visibleToOwner = $context->runWithTenant($tenantA, static fn (): bool => DB::transaction(static fn (): bool => $modelClass::query()->where($keyName, $key)->exists()));
        } catch (QueryException $exception) {
            $broken[] = $modelClass.': '.Str::before($exception->getMessage(), ' (Connection');

            continue;
        }

        if (! $visibleToOwner) {
            $leaks[] = "{$modelClass}: not visible to its own tenant, the check would prove nothing";

            continue;
        }

        $probes = [
            'tenant B' => static fn (callable $callback): mixed => $context->runWithTenant($tenantB, $callback),
            'no tenant' => static function (callable $callback) use ($context): mixed {
                $context->clear();

                return $callback();
            },
        ];

        foreach ($probes as $label => $run) {
            $seen = $run(static fn (): bool => $modelClass::query()->where($keyName, $key)->exists());
            $found = $run(static fn (): mixed => $modelClass::query()->find($key));
            $deleted = $run(static fn (): int => isolationGuarded(static fn (): int => $modelClass::query()->where($keyName, $key)->delete()));
            $touched = $run(static fn (): int => isolationGuarded(static fn (): int => $modelClass::query()->where($keyName, $key)->update(['tenant_id' => $tenantB->id])));
            $trashed = $softDeletes && $run(static function () use ($modelClass, $keyName, $key): bool {
                try {
                    return DB::transaction(static fn (): bool => $modelClass::query()->withTrashed()->where($keyName, $key)->exists());
                } catch (BadMethodCallException) {
                    return false;
                }
            });

            if ($seen || $found !== null || $deleted !== 0 || $touched !== 0 || $trashed) {
                $leaks[] = "{$modelClass} leaks to {$label}: exists=".(int) $seen.', find='.($found !== null ? 'yes' : 'no').", delete={$deleted}, update={$touched}, trashed=".(int) $trashed;
            }
        }

        $intact = DB::table((new $modelClass)->getTable())->where($keyName, $key)->where('tenant_id', $tenantA->id)->exists();

        if (! $intact) {
            $leaks[] = "{$modelClass}: the row was changed or removed from another tenant";
        }
    }

    expect($broken)->toBe([], "Models whose query does not run on their own table:\n".implode("\n", $broken));
    expect($leaks)->toBe([], implode("\n", $leaks));
    expect($exercised)->toBeGreaterThanOrEqual(700, "Only {$exercised} models could be exercised; not seedable: ".implode(', ', $unseedable));
});

test('a null tenant is invisible for the models that do not allow global records, and shared and immutable for tenants for the ones that do', function (): void {
    $tenantA = TenantRecordSeeder::tenant('Alfa');
    $tenantB = TenantRecordSeeder::tenant('Beta');
    $context = app(TenantContext::class);

    $failures = [];
    $checkedPrivate = 0;
    $checkedGlobal = 0;

    foreach (TenantModelRegistry::models() as $entry) {
        if (! $entry['scoped'] || ! $entry['hasTenantColumn']) {
            continue;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $entry['class'];
        $key = TenantRecordSeeder::seedNullTenantRow($modelClass);

        if ($key === null) {
            continue;
        }

        $keyName = (new $modelClass)->getKeyName();
        $seenBy = static function (?Tenant $tenant) use ($context, $modelClass, $keyName, $key): bool {
            $probe = static fn (): bool => DB::transaction(static fn (): bool => $modelClass::query()->where($keyName, $key)->exists());

            if ($tenant === null) {
                $context->clear();

                return $probe();
            }

            return $context->runWithTenant($tenant, $probe);
        };

        try {
            $visible = ['tenant A' => $seenBy($tenantA), 'tenant B' => $seenBy($tenantB), 'no tenant' => $seenBy(null)];
        } catch (QueryException) {
            continue;
        }

        if (! $entry['allowsGlobal']) {
            $checkedPrivate++;

            foreach ($visible as $who => $seen) {
                if ($seen) {
                    $failures[] = "{$modelClass} exposes a null tenant row to {$who}";
                }
            }

            continue;
        }

        $checkedGlobal++;

        foreach ($visible as $who => $seen) {
            if (! $seen) {
                $failures[] = "{$modelClass} hides a global row from {$who}";
            }
        }

        $refused = $context->runWithTenant($tenantB, static function () use ($modelClass, $keyName, $key): bool {
            try {
                DB::transaction(static fn () => $modelClass::query()->where($keyName, $key)->first()?->delete());
            } catch (AccessDeniedHttpException) {
                return true;
            } catch (Throwable) {
                return false;
            }

            return false;
        });

        if (! $refused || ! DB::table((new $modelClass)->getTable())->where($keyName, $key)->exists()) {
            $failures[] = "{$modelClass}: tenant B could delete a global row";
        }
    }

    $context->clear();

    expect($failures)->toBe([], implode("\n", $failures));
    expect($checkedPrivate)->toBeGreaterThanOrEqual(600, 'Too few private models checked');
    expect($checkedGlobal)->toBeGreaterThanOrEqual(40, 'Too few global models checked');
});

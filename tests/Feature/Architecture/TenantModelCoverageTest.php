<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantSchemaPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TenantModelRegistry;
use Tests\Support\TenantSchemaBaseline;

uses(RefreshDatabase::class);

/**
 * Architecture guards of the tenant boundary, over every model of app/, modules/ and packages/ and over the whole
 * schema, not only over app/Models.
 *
 * Owner policy: every table has a nullable tenant_id (null = global, visible to every tenant, only for shared data).
 *
 * - Every table has a nullable tenant_id, except the framework and central tables of TenantSchemaPolicy.
 * - A model whose table has a tenant_id column must carry the tenant scope, take its tenant from its parent, or be
 *   listed below with the reason.
 * - Every tenant_id column is indexed with tenant_id as leading column.
 * - A model may allow global records only when its table is in the frozen list of TenantSchemaBaseline.
 */

/**
 * Models with a tenant_id column that are deliberately not scoped by the global scope. Each one filters by tenant in
 * its own code (checked when the entry was written) or is reached only through a scoped parent.
 *
 * @return array<class-string, string>
 */
function unscopedModelsWithTenantColumn(): array
{
    return [
        'App\Models\EiInboundLog' => 'Inbound SDI log written by webhooks before the tenant is known; every read filters tenant_id or goes through a scoped invoice.',
        'App\Models\PasswordResetToken' => 'Central authentication data, keyed by e-mail.',
        'App\Models\TenantAccessRequest' => 'Central onboarding data read by the platform administrator.',
        'Enterprise\AuditLedger\Models\AuditLedgerEntry' => 'Append-only ledger; the API filters tenant_id explicitly.',
        'Enterprise\AuditLedger\Models\AuditLedgerArchiveEntry' => 'Append-only archive written by the retention command.',
        'Enterprise\EventBus\Models\TransactionalInbox' => 'Infrastructure inbox processed by the relay without a request tenant.',
        'Enterprise\EventBus\Models\TransactionalOutbox' => 'Infrastructure outbox processed by the relay without a request tenant.',
        'Enterprise\EventBus\Models\TransactionalOutboxArchive' => 'Infrastructure archive of the outbox.',
        'Enterprise\FeatureFlags\Models\FeatureFlag' => 'Flags are resolved by key with an explicit tenant/user/global lookup in FeatureManager.',
    ];
}

test('every model whose table has a tenant column carries the tenant scope or a documented exception', function (): void {
    $exceptions = unscopedModelsWithTenantColumn();
    $missing = [];
    $used = [];

    foreach (TenantModelRegistry::models() as $entry) {
        if (! $entry['hasTenantColumn']) {
            continue;
        }

        if ($entry['scoped'] || $entry['stamped']) {
            continue;
        }

        if (isset($exceptions[$entry['class']])) {
            $used[$entry['class']] = true;

            continue;
        }

        $missing[] = $entry['class'].' ('.$entry['table'].')';
    }

    expect($missing)->toBe([], "Models with a tenant_id column and no tenant scope (use BelongsToTenant or extend BaseEnterpriseModel):\n".implode("\n", $missing));
    expect(array_diff(array_keys($exceptions), array_keys($used)))->toBe([], 'Stale exceptions: the model is scoped now or no longer exists.');
});

test('the registry finds the models of the modules and of the packages', function (): void {
    $classes = array_column(TenantModelRegistry::models(), 'class');

    expect(count($classes))->toBeGreaterThan(700)
        ->and($classes)->toContain('Modules\GeneralLedger\Models\VatSettlement')
        ->and($classes)->toContain('Modules\Payroll\Models\Payslip')
        ->and($classes)->toContain('App\Models\Invoice');
});

test('every table has a nullable tenant_id, except the framework and central tables of the policy', function (): void {
    $schema = Schema::getConnection()->getConfig('search_path') ?: 'public';
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', $schema)
        ->where('column_name', 'tenant_id')
        ->pluck('is_nullable', 'table_name')
        ->all();
    $tables = array_column(Schema::getTables(), 'name');
    $excluded = TenantSchemaPolicy::EXCLUDED_TABLES;

    $missing = [];
    $notNullable = [];

    foreach ($tables as $table) {
        if (isset($excluded[$table])) {
            continue;
        }

        if (! isset($columns[$table])) {
            $missing[] = $table;
        } elseif ($columns[$table] !== 'YES') {
            $notNullable[] = $table;
        }
    }

    expect($missing)->toBe([], 'Tables without tenant_id: add a nullable UUID tenant_id (with an index), or list the table in TenantSchemaPolicy::EXCLUDED_TABLES with the reason.')
        ->and($notNullable)->toBe([], 'Tables whose tenant_id is NOT NULL: the policy is a nullable tenant_id on every table.')
        ->and(array_values(array_diff(array_keys($excluded), $tables)))->toBe([], 'Stale exclusions: the table no longer exists.');
});

test('the excluded tables are only framework or central tables, each with a reason', function (): void {
    foreach (TenantSchemaPolicy::EXCLUDED_TABLES as $table => $reason) {
        expect($reason)->not->toBe('', "The exclusion of {$table} has no reason.");
    }

    expect(count(TenantSchemaPolicy::EXCLUDED_TABLES))->toBeLessThanOrEqual(15, 'The exclusions must stay the exception: a business table needs a nullable tenant_id, not an exclusion.');
});

test('the child tables declared in the policy exist, have their tenant_id and a foreign key to the parent', function (): void {
    foreach (TenantSchemaPolicy::CHILD_TABLES as $child => [$parent, $foreignKey]) {
        expect(Schema::hasColumn($child, 'tenant_id'))->toBeTrue("{$child} has no tenant_id")
            ->and(Schema::hasColumn($child, $foreignKey))->toBeTrue("{$child} has no {$foreignKey}")
            ->and(Schema::hasColumn($parent, 'tenant_id'))->toBeTrue("{$parent} has no tenant_id");
    }
});

test('every tenant_id column is indexed with tenant_id as leading column', function (): void {
    $unindexed = [];

    foreach (Schema::getTables() as $table) {
        if (! Schema::hasColumn($table['name'], 'tenant_id')) {
            continue;
        }

        $indexed = collect(Schema::getIndexes($table['name']))->contains(static fn (array $index): bool => ($index['columns'][0] ?? null) === 'tenant_id');

        if (! $indexed) {
            $unindexed[] = $table['name'];
        }
    }

    expect($unindexed)->toBe([], 'Tables with tenant_id and no leading tenant index: '.implode(', ', $unindexed));
});

test('only the models of the frozen list may allow global records', function (): void {
    $allowing = [];

    foreach (TenantModelRegistry::models() as $entry) {
        if ($entry['allowsGlobal']) {
            $allowing[$entry['table']] = true;
        }
    }

    $declared = array_keys($allowing);
    sort($declared);
    $baseline = TenantSchemaBaseline::GLOBAL_TABLES;

    expect(array_values(array_diff($declared, $baseline)))->toBe([], 'A model allows global records (null tenant_id) for a table that is not in TenantSchemaBaseline::GLOBAL_TABLES: add it only if the owner agrees its rows are shared by every tenant.')
        ->and(array_values(array_diff($baseline, $declared)))->toBe([], 'The model of the table no longer allows global records: remove it from the baseline.');
});

test('the undecided tables are tenant data for now: no model allows global records for them', function (): void {
    $allowing = collect(TenantModelRegistry::models())->where('allowsGlobal', true)->pluck('table')->all();

    foreach (array_keys(TenantSchemaBaseline::UNDECIDED_TABLES) as $table) {
        expect($allowing)->not->toContain($table);
    }
});

<?php

declare(strict_types=1);

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
 * - A model whose table has a tenant_id column must carry the tenant scope, or be listed below with the reason.
 * - Every table has a tenant_id column, or is listed in the baseline with the reason.
 * - Every tenant_id column is indexed with tenant_id as leading column.
 * - A nullable tenant_id (a table that may hold generic rows) is allowed only for the tables of the frozen baseline.
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
        'App\Models\DeliveryNoteItem' => 'Document line: always read through its scoped delivery note.',
        'App\Models\InvoiceItem' => 'Document line: always read through its scoped invoice.',
        'App\Models\QuoteItem' => 'Document line: always read through its scoped quote.',
        'App\Models\SalesOrderItem' => 'Document line: always read through its scoped order (the order lists join or whereHas the scoped order).',
        'App\Models\EiInboundLog' => 'Inbound SDI log written by webhooks before the tenant is known; every read filters tenant_id or goes through a scoped invoice.',
        'App\Models\LookupItem' => 'Shared lookup catalog (null tenant = generic).',
        'App\Models\LookupItemTranslation' => 'Shared lookup catalog (null tenant = generic).',
        'App\Models\Translation' => 'Shared UI translations (null tenant = generic).',
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

        if ($entry['scoped']) {
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

test('every table has a tenant column or is documented as a child or as non tenant data', function (): void {
    $withoutColumn = [];

    foreach (Schema::getTables() as $table) {
        if (! Schema::hasColumn($table['name'], 'tenant_id')) {
            $withoutColumn[] = $table['name'];
        }
    }

    $documented = array_keys(TenantSchemaBaseline::TABLES_WITHOUT_TENANT_COLUMN);

    expect(array_values(array_diff($withoutColumn, $documented)))->toBe([], 'New tables without tenant_id: add the column or document the reason in TenantSchemaBaseline.')
        ->and(array_values(array_diff($documented, $withoutColumn)))->toBe([], 'Stale baseline entries: the table now has a tenant_id or no longer exists.');
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

test('only the tables of the frozen baseline may keep a nullable tenant_id', function (): void {
    $nullable = DB::table('information_schema.columns')
        ->where('table_schema', Schema::getConnection()->getConfig('search_path') ?: 'public')
        ->where('column_name', 'tenant_id')
        ->where('is_nullable', 'YES')
        ->pluck('table_name')
        ->all();

    $baseline = TenantSchemaBaseline::NULLABLE_TENANT_TABLES;

    expect(array_values(array_diff($nullable, $baseline)))->toBe([], 'New tables with a nullable tenant_id: make it NOT NULL, or add the table to the baseline only if its rows can be generic.')
        ->and(array_values(array_diff($baseline, $nullable)))->toBe([], 'The table is NOT NULL now: remove it from the baseline.');
});

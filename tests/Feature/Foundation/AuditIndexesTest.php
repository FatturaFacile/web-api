<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * @return object The anonymous migration class instance of the audit-index migration.
 */
function auditIndexMigration(): object
{
    return require base_path('database/migrations/2027_04_30_000100_add_parent_foreign_key_and_tenant_indexes.php');
}

test('every parent foreign key and tenant index of the audit migration exists', function (): void {
    $migration = auditIndexMigration();
    $missing = [];

    foreach ($migration::indexDefinitions() as [$table, $columns]) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table} does not exist");

        $exists = in_array($migration::indexName($table, $columns), array_column(Schema::getIndexes($table), 'name'), true);

        if (! $exists) {
            $missing[] = $table.'('.implode(',', $columns).')';
        }
    }

    expect($missing)->toBe([]);
});

test('hot line tables have an index leading with their parent key', function (): void {
    $parents = [
        'invoice_items' => 'invoice_id',
        'sales_order_items' => 'sales_order_id',
        'quote_items' => 'quote_id',
        'delivery_note_items' => 'delivery_note_id',
        'esm_ticket_messages' => 'ticket_id',
        'payroll_payslip_lines' => 'payslip_id',
        'stock_quants' => 'stock_item_id',
        'bank_statement_lines' => 'bank_statement_id',
    ];

    foreach ($parents as $table => $column) {
        $leading = collect(Schema::getIndexes($table))->filter(static fn (array $index): bool => ($index['columns'][0] ?? null) === $column);

        expect($leading->count())->toBeGreaterThan(0, "{$table}.{$column} has no leading index");
    }
});

test('the audit index migration is idempotent', function (): void {
    $migration = auditIndexMigration();
    $before = collect(Schema::getTables())->sum(static fn (array $table): int => count(Schema::getIndexes($table['name'])));

    $migration->up();
    $migration->up();

    $after = collect(Schema::getTables())->sum(static fn (array $table): int => count(Schema::getIndexes($table['name'])));

    expect((int) $after)->toBe((int) $before);
});

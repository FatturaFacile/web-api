<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $exists = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', $table)
            ->where('indexname', $migration::indexName($table, $columns))
            ->exists();

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
        $leading = DB::selectOne(
            'select count(*) as c from pg_index i join pg_class t on t.oid = i.indrelid '
            .'join pg_attribute a on a.attrelid = t.oid and a.attnum = (i.indkey::int2[])[0] '
            .'where t.relname = ? and a.attname = ?',
            [$table, $column],
        );

        expect((int) $leading->c)->toBeGreaterThan(0, "{$table}.{$column} has no leading index");
    }
});

test('the audit index migration is idempotent', function (): void {
    $migration = auditIndexMigration();
    $before = DB::selectOne("select count(*) as c from pg_indexes where schemaname = 'public'")->c;

    $migration->up();
    $migration->up();

    $after = DB::selectOne("select count(*) as c from pg_indexes where schemaname = 'public'")->c;

    expect((int) $after)->toBe((int) $before);
});

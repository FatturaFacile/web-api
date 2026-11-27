<?php

declare(strict_types=1);

use Modules\Observability\Privacy\SqlSanitizer;

it('removes every literal of a statement', function (): void {
    $sql = "SELECT * FROM users WHERE email = 'mario@x.ch' AND id = 42 AND code IN (1, 2, 3) AND price > 12.50 -- trailing comment\n AND note = 'it''s'";

    expect((new SqlSanitizer)->normalize($sql))
        ->toBe('SELECT * FROM users WHERE email = ? AND id = ? AND code IN (?) AND price > ? AND note = ?');
});

it('keeps identifiers that contain digits and quoted names', function (): void {
    expect((new SqlSanitizer)->normalize('select t1.col_2, "Table3".x from t1 join "Table3" on t1.id = "Table3".t1_id limit 10'))
        ->toBe('select t1.col_2, "Table3".x from t1 join "Table3" on t1.id = "Table3".t1_id limit ?');
});

it('collapses multi-row inserts and numbered parameters', function (): void {
    $sanitizer = new SqlSanitizer;

    expect($sanitizer->normalize("insert into t (a, b) values (1, 'x'), (2, 'y'), (3, 'z')"))->toBe('insert into t (a, b) values (?)')
        ->and($sanitizer->normalize('select * from t where a = $1 and b = $2'))->toBe('select * from t where a = ? and b = ?');
});

it('gives the same fingerprint to the same query with other values', function (): void {
    $sanitizer = new SqlSanitizer;
    $one = $sanitizer->fingerprint($sanitizer->normalize("select * from invoices where id = 1 and status = 'open'"));
    $two = $sanitizer->fingerprint($sanitizer->normalize("SELECT * FROM invoices WHERE id = 999 AND status = 'paid'"));
    $other = $sanitizer->fingerprint($sanitizer->normalize('select * from contacts where id = 1'));

    expect($one)->toBe($two)->and($one)->toHaveLength(40)->and($one)->not->toBe($other);
});

it('detects select statements', function (string $sql, bool $select): void {
    expect((new SqlSanitizer)->isSelect($sql))->toBe($select);
})->with([
    ['select 1', true], ['  SELECT * from t', true], ['with x as (select 1) select * from x', true], ['(select 1)', true],
    ['insert into t values (1)', false], ['update t set a = 1', false], ['delete from t', false],
]);

it('reduces the literals of the filters and conditions of a plan', function (): void {
    $plan = (new SqlSanitizer)->sanitizePlan(['Plan' => [
        'Node Type' => 'Seq Scan',
        'Filter' => "((status)::text = 'open'::text)",
        'Plans' => [['Index Cond' => "(tenant_id = 'abc-123'::uuid)", 'Relation Name' => 'invoices']],
    ]]);

    expect($plan['Plan']['Filter'])->toBe('((status)::text = ?::text)')
        ->and($plan['Plan']['Plans'][0]['Index Cond'])->toBe('(tenant_id = ?::uuid)')
        ->and($plan['Plan']['Plans'][0]['Relation Name'])->toBe('invoices');
});

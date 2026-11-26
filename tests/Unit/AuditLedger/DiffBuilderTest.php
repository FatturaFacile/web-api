<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Domain\Services\DiffBuilder;

test('a created record has only new values', function (): void {
    expect((new DiffBuilder)->build(null, ['name' => 'Acme', 'city' => 'Lugano']))
        ->toBe(['old' => [], 'new' => ['name' => 'Acme', 'city' => 'Lugano']]);
});

test('a deleted record has only old values', function (): void {
    expect((new DiffBuilder)->build(['name' => 'Acme'], null))->toBe(['old' => ['name' => 'Acme'], 'new' => []]);
});

test('an update keeps only the attributes that changed', function (): void {
    $diff = (new DiffBuilder)->build(['name' => 'Acme', 'city' => 'Lugano', 'vat' => 'CHE1'], ['name' => 'Acme SA', 'city' => 'Lugano']);

    expect($diff)->toBe(['old' => ['name' => 'Acme'], 'new' => ['name' => 'Acme SA']]);
});

test('only the attributes named in the after state are compared', function (): void {
    $diff = (new DiffBuilder)->build(['a' => 1, 'b' => 2, 'c' => 3], ['b' => 5]);

    expect($diff)->toBe(['old' => ['b' => 2], 'new' => ['b' => 5]]);
});

test('values are compared in their canonical form', function (): void {
    $diff = (new DiffBuilder)->build(['total' => 10.0, 'meta' => ['b' => 1, 'a' => 2]], ['total' => 10.0, 'meta' => ['a' => 2, 'b' => 1]]);

    expect($diff)->toBe(['old' => [], 'new' => []]);
});

test('a new attribute counts as a change from null', function (): void {
    expect((new DiffBuilder)->build(['a' => 1], ['a' => 1, 'b' => 2]))->toBe(['old' => ['b' => null], 'new' => ['b' => 2]]);
});

test('nothing on either side is an empty diff', function (): void {
    expect((new DiffBuilder)->build(null, null))->toBe(['old' => [], 'new' => []]);
});

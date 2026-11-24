<?php

declare(strict_types=1);

use Modules\OcrExtraction\Domain\Services\LineDescriptionMatcher;
use Modules\OcrExtraction\Domain\Services\ThreeWayMatchEvaluator;
use Modules\OcrExtraction\Infrastructure\Models\VendorCodingPredictionModel;

test('a description reduces to the signature of the item bought', function (): void {
    $matcher = new LineDescriptionMatcher;

    expect($matcher->normalize('Consulenza IT del 12.03.2026 - 3 ore'))->toBe('consulenza it')
        ->and($matcher->normalize('Beratung der IT, 5 Stk'))->toBe('beratung it')
        ->and($matcher->normalize('Toner HP 305A noir'))->toBe($matcher->normalize('NOIR toner hp  305a'))
        ->and(mb_strlen($matcher->normalize(str_repeat('abcdefgh ', 200))))->toBeLessThanOrEqual(512);
});

test('similarity is 1 for equal signatures, high for near ones and low for different ones', function (): void {
    $matcher = new LineDescriptionMatcher;

    expect($matcher->similarity('toner noir', 'toner noir'))->toBe(1.0)
        ->and($matcher->similarity('toner noir', 'toner noire'))->toBeGreaterThan(0.8)
        ->and($matcher->similarity('toner noir', 'carburant diesel'))->toBeLessThan(0.4)
        ->and($matcher->similarity('', 'toner'))->toBe(0.0);
});

test('accuracy is hits over hits plus corrections', function (): void {
    expect(VendorCodingPredictionModel::accuracyFor(3, 1))->toBe(75.0)
        ->and(VendorCodingPredictionModel::accuracyFor(1, 0))->toBe(100.0)
        ->and(VendorCodingPredictionModel::accuracyFor(1, 1))->toBe(50.0)
        ->and(VendorCodingPredictionModel::accuracyFor(0, 0))->toBe(100.0);
});

test('the three-way match measures price and quantity variances against order and receipts', function (): void {
    $orderLines = [
        ['id' => 'l1', 'description' => 'Toner noir HP', 'quantity' => 10.0, 'unit_price' => 50.0, 'quantity_received' => 10.0],
        ['id' => 'l2', 'description' => 'Carta A4', 'quantity' => 100.0, 'unit_price' => 5.0, 'quantity_received' => 60.0],
    ];
    $evaluator = new ThreeWayMatchEvaluator;

    $clean = $evaluator->evaluate([
        ['description' => 'Toner noir HP', 'quantity' => 10.0, 'unit_price' => 50.0],
        ['description' => 'Carta A4', 'quantity' => 60.0, 'unit_price' => 5.0],
    ], $orderLines, 2.0, 1.0);

    expect($clean['status'])->toBe('matched')
        ->and($clean['lines'])->toHaveCount(2)
        ->and($clean['lines'][1]['quantity_variance'])->toBe(-40.0);

    $varied = $evaluator->evaluate([
        ['description' => 'Toner noir HP', 'quantity' => 10.0, 'unit_price' => 55.0],
        ['description' => 'Carta A4', 'quantity' => 100.0, 'unit_price' => 5.0],
        ['description' => 'Spedizione espressa', 'quantity' => 1.0, 'unit_price' => 20.0],
    ], $orderLines, 2.0, 1.0);

    expect($varied['status'])->toBe('variance')
        ->and($varied['lines'][0]['price_variance'])->toBe(5.0)
        ->and($varied['lines'][0]['price_variance_percent'])->toBe(10.0)
        ->and($varied['lines'][0]['within_tolerance'])->toBeFalse()
        ->and($varied['lines'][1]['received_variance'])->toBe(40.0)
        ->and($varied['lines'][1]['within_tolerance'])->toBeFalse()
        ->and($varied['unmatched_invoice_lines'])->toBe([2]);

    expect($evaluator->evaluate([['description' => 'Qualcosa di diverso', 'quantity' => 1.0, 'unit_price' => 1.0]], $orderLines, 2.0, 1.0)['status'])->toBe('unmatched');
});

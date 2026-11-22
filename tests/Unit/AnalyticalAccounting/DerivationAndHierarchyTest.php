<?php

declare(strict_types=1);

use Modules\AnalyticalAccounting\Domain\Services\AnalyticalDerivationService;
use Modules\AnalyticalAccounting\Domain\Services\HierarchyValidatorService;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adRule(string $id, int $priority, array $overrides = []): array
{
    return ['id' => $id, 'priority' => $priority, 'is_active' => true, 'valid_from' => null, 'valid_to' => null, ...$overrides];
}

it('applies the rule with the lowest priority number first and fills the rest from later rules', function (): void {
    $rules = [
        adRule('r2', 20, ['account_id' => 'acc', 'cost_center_id' => 'CC-LOW', 'profit_center_id' => 'PC-LOW']),
        adRule('r1', 10, ['account_id' => 'acc', 'cost_center_id' => 'CC-HIGH']),
    ];

    $result = (new AnalyticalDerivationService)->resolve($rules, ['account_id' => 'acc', 'date' => '2027-03-01']);

    expect($result['dimensions']['cost_center_id'])->toBe('CC-HIGH')
        ->and($result['dimensions']['profit_center_id'])->toBe('PC-LOW')
        ->and($result['provenance'])->toBe(['cost_center_id' => 'RULE', 'profit_center_id' => 'RULE'])
        ->and($result['source'])->toBe('RULE')
        ->and($result['rule_ids'])->toEqualCanonicalizing(['r1', 'r2']);
});

it('prefers the most specific rule at equal priority', function (): void {
    $rules = [
        adRule('generic', 10, ['account_id' => 'acc', 'cost_center_id' => 'CC-GENERIC']),
        adRule('specific', 10, ['account_id' => 'acc', 'product_id' => 'prod', 'cost_center_id' => 'CC-SPECIFIC']),
    ];

    $result = (new AnalyticalDerivationService)->resolve($rules, ['account_id' => 'acc', 'product_id' => 'prod', 'date' => '2027-03-01']);

    expect($result['dimensions']['cost_center_id'])->toBe('CC-SPECIFIC');
});

it('never overrides a manual dimension', function (): void {
    $rules = [adRule('r', 1, ['account_id' => 'acc', 'cost_center_id' => 'CC-RULE', 'project_id' => 'P-RULE'])];

    $result = (new AnalyticalDerivationService)->resolve($rules, ['account_id' => 'acc', 'date' => '2027-03-01'], ['cost_center_id' => 'CC-MANUAL']);

    expect($result['dimensions']['cost_center_id'])->toBe('CC-MANUAL')
        ->and($result['dimensions']['project_id'])->toBe('P-RULE')
        ->and($result['provenance']['cost_center_id'])->toBe('MANUAL')
        ->and($result['source'])->toBe('MANUAL');
});

it('ignores inactive, expired, future and non-matching rules', function (): void {
    $rules = [
        adRule('off', 1, ['account_id' => 'acc', 'cost_center_id' => 'A', 'is_active' => false]),
        adRule('expired', 1, ['account_id' => 'acc', 'cost_center_id' => 'B', 'valid_to' => '2027-01-31']),
        adRule('future', 1, ['account_id' => 'acc', 'cost_center_id' => 'C', 'valid_from' => '2027-06-01']),
        adRule('other', 1, ['account_id' => 'other', 'cost_center_id' => 'D']),
    ];

    $result = (new AnalyticalDerivationService)->resolve($rules, ['account_id' => 'acc', 'date' => '2027-03-01']);

    expect($result['dimensions']['cost_center_id'])->toBeNull()->and($result['rule_ids'])->toBe([]);
});

it('falls back to the master defaults of the resolved center', function (): void {
    $defaults = ['CC1' => ['default_profit_center_id' => 'PC-DEF', 'default_cost_element_type' => 'FIXED_DIRECT', 'default_project_id' => null, 'default_product_line_id' => null]];

    $result = (new AnalyticalDerivationService)->resolve([], ['date' => '2027-03-01'], ['cost_center_id' => 'CC1'], $defaults);

    expect($result['dimensions']['profit_center_id'])->toBe('PC-DEF')
        ->and($result['dimensions']['cost_element_type'])->toBe('FIXED_DIRECT')
        ->and($result['provenance']['profit_center_id'])->toBe('MASTER_DEFAULT');
});

it('matches on accounting cause, journal type and counterparty', function (): void {
    $rules = [adRule('r', 1, ['accounting_cause_id' => 'cause', 'counterparty_type' => 'supplier', 'cost_center_id' => 'CC'])];
    $service = new AnalyticalDerivationService;

    expect($service->resolve($rules, ['accounting_cause_id' => 'cause', 'counterparty_type' => 'supplier', 'date' => '2027-01-01'])['dimensions']['cost_center_id'])->toBe('CC')
        ->and($service->resolve($rules, ['accounting_cause_id' => 'cause', 'counterparty_type' => 'customer', 'date' => '2027-01-01'])['dimensions']['cost_center_id'])->toBeNull();
});

/**
 * @return array<string, array{parent_id: string|null, center_type: string, valid_from: string|null, valid_to: string|null}>
 */
function adTree(): array
{
    $node = static fn (?string $parent, string $type = 'COST_CENTER'): array => ['parent_id' => $parent, 'center_type' => $type, 'valid_from' => null, 'valid_to' => null];

    return ['root' => $node(null), 'a' => $node('root'), 'b' => $node('a'), 'c' => $node('b')];
}

it('detects cycles and self parents', function (): void {
    $validator = new HierarchyValidatorService;
    $candidate = static fn (string $parent): array => ['parent_id' => $parent, 'center_type' => 'COST_CENTER', 'valid_from' => null, 'valid_to' => null];

    expect(fn () => $validator->assertValid(adTree(), 'root', $candidate('c')))->toThrow(InvalidArgumentException::class, 'cycle')
        ->and(fn () => $validator->assertValid(adTree(), 'a', $candidate('a')))->toThrow(InvalidArgumentException::class, 'own parent')
        ->and(fn () => $validator->assertValid(adTree(), 'c', $candidate('root')))->not->toThrow(InvalidArgumentException::class);
});

it('rejects a profit center under a cost center and a missing parent', function (): void {
    $validator = new HierarchyValidatorService;

    expect(fn () => $validator->assertValid(adTree(), 'new', ['parent_id' => 'a', 'center_type' => 'PROFIT_CENTER', 'valid_from' => null, 'valid_to' => null]))->toThrow(InvalidArgumentException::class, 'profit center')
        ->and(fn () => $validator->assertValid(adTree(), 'new', ['parent_id' => 'ghost', 'center_type' => 'COST_CENTER', 'valid_from' => null, 'valid_to' => null]))->toThrow(InvalidArgumentException::class, 'does not exist');
});

it('keeps the validity of a child inside its parent', function (): void {
    $tree = adTree();
    $tree['a']['valid_from'] = '2027-01-01';
    $tree['a']['valid_to'] = '2027-12-31';
    $validator = new HierarchyValidatorService;

    expect(fn () => $validator->assertValid($tree, 'new', ['parent_id' => 'a', 'center_type' => 'COST_CENTER', 'valid_from' => '2026-12-01', 'valid_to' => '2027-06-30']))->toThrow(InvalidArgumentException::class, 'before its parent')
        ->and(fn () => $validator->assertValid($tree, 'new', ['parent_id' => 'a', 'center_type' => 'COST_CENTER', 'valid_from' => '2027-02-01', 'valid_to' => null]))->toThrow(InvalidArgumentException::class, 'after its parent');
});

it('caps the depth and computes ancestors, descendants and materialized paths', function (): void {
    $validator = new HierarchyValidatorService;
    $deep = ['n0' => ['parent_id' => null, 'center_type' => 'COST_CENTER', 'valid_from' => null, 'valid_to' => null]];
    for ($i = 1; $i <= HierarchyValidatorService::MaxDepth; $i++) {
        $deep["n{$i}"] = ['parent_id' => 'n'.($i - 1), 'center_type' => 'COST_CENTER', 'valid_from' => null, 'valid_to' => null];
    }

    expect(fn () => $validator->assertValid($deep, 'x', ['parent_id' => 'n'.HierarchyValidatorService::MaxDepth, 'center_type' => 'COST_CENTER', 'valid_from' => null, 'valid_to' => null]))->toThrow(InvalidArgumentException::class, 'deeper')
        ->and($validator->ancestorIds(adTree(), 'c'))->toBe(['c', 'b', 'a', 'root'])
        ->and($validator->descendantIds(adTree(), 'a'))->toBe(['b', 'c'])
        ->and($validator->depthOf(adTree(), 'c'))->toBe(3)
        ->and($validator->pathOf(adTree(), 'b'))->toBe('/root/a/b/');
});

it('accepts postings only on active centers inside their validity window', function (): void {
    $validator = new HierarchyValidatorService;
    $center = ['is_active' => true, 'valid_from' => '2027-02-01', 'valid_to' => '2027-02-28'];

    expect($validator->acceptsPostings($center, '2027-02-15'))->toBeTrue()
        ->and($validator->acceptsPostings($center, '2027-03-01'))->toBeFalse()
        ->and($validator->acceptsPostings($center, '2027-01-31'))->toBeFalse()
        ->and($validator->acceptsPostings([...$center, 'is_active' => false], '2027-02-15'))->toBeFalse();
});

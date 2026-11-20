<?php

declare(strict_types=1);

use Modules\Academy\Domain\ValueObjects\GuidedTourStep;
use Modules\Academy\Domain\ValueObjects\VerificationRuleSpec;
use Modules\Academy\Services\VerificationRuleEvaluator;

beforeEach(function (): void {
    $this->evaluator = new VerificationRuleEvaluator;
});

test('an empty predicate matches every event of the target type and only that type', function (): void {
    $rule = new VerificationRuleSpec('invoicing.invoice.posted');

    expect($this->evaluator->matches($rule, 'invoicing.invoice.posted', []))->toBeTrue()
        ->and($this->evaluator->matches($rule, 'invoicing.invoice.paid', []))->toBeFalse();
});

test('comparison operators work on numbers, numeric strings and dot-notation paths', function (string $predicate, bool $expected): void {
    $payload = ['total_gross' => '120.50', 'status' => 'paid', 'customer' => ['country' => 'CH'], 'lines' => [['qty' => 3]]];

    expect($this->evaluator->evaluate($predicate, $payload))->toBe($expected);
})->with([
    ['total_gross >= 100', true],
    ['total_gross > 120.5', false],
    ['total_gross <= 120.5', true],
    ['total_gross < 50', false],
    ['status == "paid"', true],
    ['status != "paid"', false],
    ['customer.country == "CH"', true],
    ['lines.0.qty == 3', true],
    ['status in ["draft","paid"]', true],
    ['status not_in ["draft","paid"]', false],
    ['customer exists', true],
    ['missing exists', false],
    ['status contains "ai"', true],
]);

test('AND binds tighter than OR', function (): void {
    $payload = ['a' => 1, 'b' => 2];

    expect($this->evaluator->evaluate('a == 9 AND b == 2', $payload))->toBeFalse()
        ->and($this->evaluator->evaluate('a == 9 AND b == 2 OR a == 1', $payload))->toBeTrue()
        ->and($this->evaluator->evaluate('a == 1 AND b == 2', $payload))->toBeTrue();
});

test('anything that is not in the grammar is false and never executed', function (string $predicate): void {
    expect($this->evaluator->evaluate($predicate, ['a' => 1]))->toBeFalse();
})->with([
    'phpinfo()',
    'a == system("id")',
    'a == 1; unlink("x")',
    'a ~= 1',
    'a == {broken',
]);

test('a missing value never satisfies a numeric comparison', function (): void {
    expect($this->evaluator->evaluate('amount > 0', []))->toBeFalse()
        ->and($this->evaluator->evaluate('amount == 0', []))->toBeFalse();
});

test('a rule needs a target event and round-trips through its array form', function (): void {
    $rule = VerificationRuleSpec::fromArray(['target_event' => 'hr.employee.hired', 'predicate' => 'x exists', 'sandbox_only' => false]);

    expect($rule->toArray())->toBe(['target_event' => 'hr.employee.hired', 'predicate' => 'x exists', 'sandbox_only' => false]);
    expect(fn () => new VerificationRuleSpec(' '))->toThrow(InvalidArgumentException::class);
});

test('a guided tour step round-trips through its array form', function (): void {
    $step = GuidedTourStep::fromArray(['route' => '/invoices', 'selector' => '#a', 'texts' => ['en' => 'Click'], 'placement' => 'left']);

    expect($step->toArray())->toBe(['route' => '/invoices', 'selector' => '#a', 'texts' => ['en' => 'Click'], 'placement' => 'left']);
});

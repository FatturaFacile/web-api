<?php

declare(strict_types=1);

use Modules\CorporateConventions\Domain\Enums\RuleType;
use Modules\CorporateConventions\Domain\Enums\SubsidyType;
use Modules\CorporateConventions\Domain\Support\DiscountMatrixEvaluator;
use Modules\CorporateConventions\Domain\ValueObjects\BeneficiaryBalance;
use Modules\CorporateConventions\Domain\ValueObjects\CartLine;
use Modules\CorporateConventions\Domain\ValueObjects\ConventionTerms;
use Modules\CorporateConventions\Domain\ValueObjects\DiscountRuleSpec;

function ccvRule(string $id, int $priority, string $percentage, RuleType $type = RuleType::GlobalPercentage, bool $cumulative = false, bool $excludePromo = false, ?string $category = null, ?string $min = null, ?string $priceList = null): DiscountRuleSpec
{
    return new DiscountRuleSpec($id, $priority, $type, $percentage, $excludePromo, $cumulative, $priceList, $category, $min);
}

function ccvLine(string $id, string $price, string $qty = '1', ?string $category = null, bool $promo = false, string $existing = '0', array $priceLists = [], array $path = []): CartLine
{
    return new CartLine($id, $qty, $price, '0', $category, $path, $promo, $existing, $priceLists);
}

it('applies a global percentage to the whole cart', function (): void {
    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00'), ccvLine('b', '50.00', '2')], [ccvRule('r1', 1, '10')], new ConventionTerms, new BeneficiaryBalance);

    expect($result->totalGross)->toBe('200.00')
        ->and($result->conventionDiscount)->toBe('20.00')
        ->and($result->payableAfterDiscount)->toBe('180.00')
        ->and($result->employerSubsidy)->toBe('0.00')
        ->and($result->employeePaid)->toBe('180.00');
});

it('lets a non-cumulative rule end the chain so a later rule never applies', function (): void {
    $rules = [ccvRule('cat', 1, '20', RuleType::CategoryPercentage, false, false, 'cat-1'), ccvRule('global', 2, '10')];

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00', '1', 'cat-1'), ccvLine('b', '100.00', '1', 'other')], $rules, new ConventionTerms, new BeneficiaryBalance);

    expect($result->lines[0]['convention_discount'])->toBe('20.00')
        ->and($result->lines[0]['applied_rule_ids'])->toBe(['cat'])
        ->and($result->lines[1]['convention_discount'])->toBe('10.00')
        ->and($result->conventionDiscount)->toBe('30.00');
});

it('stacks cumulative rules on the price left by the previous discount', function (): void {
    $rules = [ccvRule('r1', 1, '10', cumulative: true), ccvRule('r2', 2, '10', cumulative: true)];

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00')], $rules, new ConventionTerms, new BeneficiaryBalance);

    expect($result->conventionDiscount)->toBe('19.00')
        ->and($result->lines[0]['net'])->toBe('81.00')
        ->and($result->lines[0]['applied_rule_ids'])->toBe(['r1', 'r2']);
});

it('evaluates rules by ascending priority whatever the input order', function (): void {
    $rules = [ccvRule('late', 5, '5'), ccvRule('early', 1, '25')];

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00')], $rules, new ConventionTerms, new BeneficiaryBalance);

    expect($result->lines[0]['applied_rule_ids'])->toBe(['early'])
        ->and($result->conventionDiscount)->toBe('25.00');
});

it('skips promotional lines when the rule excludes them', function (): void {
    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('promo', '100.00', promo: true), ccvLine('std', '100.00')], [ccvRule('r', 1, '10', excludePromo: true)], new ConventionTerms, new BeneficiaryBalance);

    expect($result->lines[0]['convention_discount'])->toBe('0.00')
        ->and($result->lines[1]['convention_discount'])->toBe('10.00');
});

it('does not apply a rule below its minimum cart amount', function (): void {
    $rule = ccvRule('r', 1, '10', min: '200.00');

    $below = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00')], [$rule], new ConventionTerms, new BeneficiaryBalance);
    $above = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '250.00')], [$rule], new ConventionTerms, new BeneficiaryBalance);

    expect($below->conventionDiscount)->toBe('0.00')
        ->and($above->conventionDiscount)->toBe('25.00');
});

it('never raises a price: a price list dearer than the carried price is ignored', function (): void {
    $rule = ccvRule('pl', 1, '0', RuleType::PriceList, priceList: 'list-1');

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00', priceLists: ['list-1' => '120.00'])], [$rule], new ConventionTerms, new BeneficiaryBalance);

    expect($result->conventionDiscount)->toBe('0.00')
        ->and($result->payableAfterDiscount)->toBe('100.00');
});

it('uses the convention price list when it is cheaper', function (): void {
    $rule = ccvRule('pl', 1, '0', RuleType::PriceList, priceList: 'list-1');

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00', priceLists: ['list-1' => '80.00'])], [$rule], new ConventionTerms, new BeneficiaryBalance);

    expect($result->conventionDiscount)->toBe('20.00')
        ->and($result->payableAfterDiscount)->toBe('80.00');
});

it('matches a product family through the category path', function (): void {
    $rule = ccvRule('fam', 1, '10', RuleType::ProductFamilyPercentage, category: 'parent');

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00', category: 'child', path: ['parent', 'child'])], [$rule], new ConventionTerms, new BeneficiaryBalance);

    expect($result->conventionDiscount)->toBe('10.00');
});

it('scales the discount to what is left of the annual spending cap', function (): void {
    $terms = new ConventionTerms(annualSpendingCap: '100.00');

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '200.00')], [ccvRule('r', 1, '10')], $terms, new BeneficiaryBalance);

    expect($result->capReached)->toBeTrue()
        ->and($result->cappedSpend)->toBe('100.00')
        ->and($result->conventionDiscount)->toBe('11.11');
});

it('gives no discount once the annual cap is exhausted', function (): void {
    $terms = new ConventionTerms(annualSpendingCap: '100.00');

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '200.00')], [ccvRule('r', 1, '10')], $terms, new BeneficiaryBalance('100.00'));

    expect($result->conventionDiscount)->toBe('0.00')
        ->and($result->payableAfterDiscount)->toBe('200.00')
        ->and($result->lines[0]['applied_rule_ids'])->toBe([]);
});

it('computes a percentage employer subsidy on the payable amount', function (): void {
    $terms = new ConventionTerms(null, true, SubsidyType::Percentage, '50');

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00')], [ccvRule('r', 1, '10')], $terms, new BeneficiaryBalance);

    expect($result->payableAfterDiscount)->toBe('90.00')
        ->and($result->employerSubsidy)->toBe('45.00')
        ->and($result->employeePaid)->toBe('45.00');
});

it('caps a fixed employer subsidy at the payable amount and at the remaining annual subsidy', function (): void {
    $fixed = new ConventionTerms(null, true, SubsidyType::FixedAmount, '200.00');
    $capped = new ConventionTerms(null, true, SubsidyType::FixedAmount, '30.00', '50.00');

    $first = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00')], [], $fixed, new BeneficiaryBalance);
    $second = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00')], [], $capped, new BeneficiaryBalance('0', '40.00'));

    expect($first->employerSubsidy)->toBe('100.00')
        ->and($first->employeePaid)->toBe('0.00')
        ->and($second->employerSubsidy)->toBe('10.00')
        ->and($second->employeePaid)->toBe('90.00');
});

it('never grants the employer subsidy to family members', function (): void {
    $terms = new ConventionTerms(null, true, SubsidyType::Percentage, '50');

    $result = (new DiscountMatrixEvaluator)->evaluate([ccvLine('a', '100.00')], [], $terms, new BeneficiaryBalance('0', '0', false));

    expect($result->employerSubsidy)->toBe('0.00')
        ->and($result->employeePaid)->toBe('100.00');
});

it('keeps the supplier cost of the cart for the margin analysis', function (): void {
    $line = new CartLine('a', '2', '100.00', '30.00');

    $result = (new DiscountMatrixEvaluator)->evaluate([$line], [ccvRule('r', 1, '10')], new ConventionTerms, new BeneficiaryBalance);

    expect($result->totalCost)->toBe('60.00');
});

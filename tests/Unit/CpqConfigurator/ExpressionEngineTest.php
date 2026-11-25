<?php

declare(strict_types=1);

use Modules\CpqConfigurator\Exceptions\ExpressionException;
use Modules\CpqConfigurator\Services\Expression\ExpressionEvaluator;
use Modules\CpqConfigurator\Services\Expression\ExpressionParser;

/**
 * @param  array<string, mixed>  $variables
 */
function cpqEval(string $source, array $variables = []): mixed
{
    return (new ExpressionEvaluator)->evaluateSource($source, $variables);
}

it('evaluates arithmetic with the usual precedence and a right associative power', function (string $source, float $expected): void {
    expect(cpqEval($source))->toEqualWithDelta($expected, 1e-9);
})->with([
    ['1 + 2 * 3', 7],
    ['(1 + 2) * 3', 9],
    ['10 / 4', 2.5],
    ['10 % 4', 2],
    ['2 ^ 3 ^ 2', 512],
    ['-2 ^ 2', -4],
    ['-(2 + 3)', -5],
    ['1.5e2 + .5', 150.5],
    ['round(2.345, 2)', 2.35],
    ['round(2.5)', 3],
    ['ceil(2.1)', 3],
    ['floor(2.9)', 2],
    ['abs(-4)', 4],
    ['sqrt(81)', 9],
    ['pow(2, 10)', 1024],
    ['min(3, 1, 2)', 1],
    ['max(3, 1, 2)', 3],
    ['max([4, 9, 2])', 9],
    ['if(1 > 2, 10, 20)', 20],
    ['coalesce(null, null, 7)', 7],
]);

it('reads the characteristics of the configuration as variables, also nested ones', function (): void {
    $variables = ['flow_rate' => 40.0, 'material' => 'AISI_316', 'attr' => ['material' => ['density' => 8.0]]];

    expect(cpqEval('flow_rate * attr.material.density', $variables))->toEqualWithDelta(320.0, 1e-9)
        ->and(cpqEval("material == 'AISI_316'", $variables))->toBeTrue()
        ->and(cpqEval('missing_variable', $variables))->toBeNull()
        ->and(cpqEval('attr.material.missing', $variables))->toBeNull();
});

it('compares numbers, strings, booleans and null', function (string $source, bool $expected): void {
    expect(cpqEval($source, ['a' => 3, 'b' => 'CH', 'on' => true, 'off' => false, 'nothing' => null]))->toBe($expected);
})->with([
    ['a == 3', true],
    ['a != 3', false],
    ['a >= 3 and a <= 3', true],
    ['a > 3', false],
    ['a < 4', true],
    ["b == 'CH'", true],
    ["b != 'IT'", true],
    ['on', true],
    ['not off', true],
    ['!on', false],
    ['nothing == null', true],
    ['nothing < 5', false],
    ['nothing > 5', false],
    ['3 == 3.0000000001', true],
]);

it('supports in and not in over lists, with and without variables', function (string $source, bool $expected): void {
    expect(cpqEval($source, ['country' => 'CH', 'eu' => ['IT', 'DE']]))->toBe($expected);
})->with([
    ["country in ['CH', 'LI']", true],
    ["country in ['IT', 'DE']", false],
    ["country not in ['IT', 'DE']", true],
    ['country in eu', false],
    ['3 in [1, 2, 3]', true],
]);

it('short-circuits and, or and if so the unused branch is never evaluated', function (): void {
    expect(cpqEval('false and (1 / 0 > 1)'))->toBeFalse()
        ->and(cpqEval('true or (1 / 0 > 1)'))->toBeTrue()
        ->and(cpqEval('if(true, 1, 1 / 0)'))->toEqualWithDelta(1.0, 1e-9);
});

it('rejects what it cannot evaluate with a clear error', function (string $source): void {
    expect(fn () => cpqEval($source, ['text' => 'abc']))->toThrow(ExpressionException::class);
})->with([
    'division by zero' => ['1 / 0'],
    'modulo by zero' => ['5 % 0'],
    'text in arithmetic' => ['text * 2'],
    'unknown function' => ['system(1)'],
    'syntax error' => ['1 +'],
    'unbalanced parenthesis' => ['(1 + 2'],
    'unknown character' => ['1 # 2'],
    'unclosed string' => ["'abc"],
    'wrong arity' => ['round(1, 2, 3)'],
    'empty' => ['   '],
    'not finite' => ['(-8) ^ 0.5'],
    'negative root' => ['sqrt(-1)'],
]);

it('never executes php: code-like input is a syntax error, not an eval', function (): void {
    foreach (['system("ls")', '`ls`', '$x = 1', 'phpinfo()', '1; die()'] as $source) {
        expect(fn () => cpqEval($source))->toThrow(ExpressionException::class);
    }
});

it('limits the size and the nesting of an expression', function (): void {
    expect(fn () => ExpressionParser::parse(str_repeat('1+', 1500).'1'))->toThrow(ExpressionException::class)
        ->and(fn () => ExpressionParser::parse(str_repeat('(', 60).'1'.str_repeat(')', 60)))->toThrow(ExpressionException::class);
});

it('lists the variables an expression reads, sorted and without duplicates', function (): void {
    $expression = ExpressionParser::parse("flow_rate * head > 100 and material in ['A'] or attr.material.density > flow_rate");

    expect($expression->variables())->toBe(['attr.material.density', 'flow_rate', 'head', 'material']);
});

it('treats an empty condition as true and an empty formula as the default', function (): void {
    $evaluator = new ExpressionEvaluator;

    expect($evaluator->isTrue(null, []))->toBeTrue()
        ->and($evaluator->isTrue('  ', []))->toBeTrue()
        ->and($evaluator->number(null, [], 5.0))->toBe(5.0)
        ->and($evaluator->number('1 / 3', []))->toBe(0.3333);
});

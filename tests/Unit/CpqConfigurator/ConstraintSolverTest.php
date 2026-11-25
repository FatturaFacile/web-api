<?php

declare(strict_types=1);

use Modules\CpqConfigurator\Services\ConstraintSolver;

require_once __DIR__.'/CpqFixtures.php';

it('applies the default option and the default numeric value when nothing is answered', function (): void {
    $definition = cpqDefinition([cpqList('voltage', ['V230', 'V400'], 'V400'), cpqCharacteristic('power', 'range', ['min' => 1.0, 'max' => 10.0, 'step_size' => 1.0, 'default' => '4'])]);

    $solution = (new ConstraintSolver)->solve($definition, []);

    expect($solution['values'])->toBe(['voltage' => 'V400', 'power' => 4.0])
        ->and($solution['valid'])->toBeTrue()
        ->and($solution['complete'])->toBeTrue();
});

it('reports the unanswered required characteristics as missing, not as errors', function (): void {
    $solution = (new ConstraintSolver)->solve(cpqDefinition([cpqList('voltage', ['V230', 'V400'])]), []);

    expect($solution['missing'])->toBe(['voltage'])
        ->and($solution['valid'])->toBeTrue()
        ->and($solution['complete'])->toBeFalse();
});

it('blocks an option with the explanation of the rule and flags a selection that uses it', function (): void {
    $definition = cpqDefinition(
        [cpqList('fluid', ['WATER', 'ACID']), cpqList('material', ['AISI_304', 'AISI_316'])],
        [cpqRule('acid', 'exclude_options', "fluid == 'ACID'", ['target' => 'material', 'target_options' => ['AISI_304'], 'message' => ['en' => 'Acid needs AISI 316 (fluid {fluid})', 'it' => 'Acido: serve AISI 316']]),
        ],
    );
    $solver = new ConstraintSolver;

    $free = $solver->solve($definition, ['fluid' => 'WATER', 'material' => 'AISI_304'], 'en');
    $blocked = $solver->solve($definition, ['fluid' => 'ACID', 'material' => 'AISI_304'], 'en');
    $italian = $solver->solve($definition, ['fluid' => 'ACID', 'material' => 'AISI_316'], 'it');

    expect($free['valid'])->toBeTrue()
        ->and($free['disabled'])->toBe([])
        ->and($blocked['valid'])->toBeFalse()
        ->and($blocked['disabled']['material']['AISI_304'])->toBe(['Acid needs AISI 316 (fluid ACID)'])
        ->and(cpqViolationCodes($blocked))->toBe(['option_blocked'])
        ->and($italian['valid'])->toBeTrue()
        ->and($italian['disabled']['material']['AISI_304'])->toBe(['Acido: serve AISI 316']);
});

it('includes a value automatically and says which rule did it', function (): void {
    $definition = cpqDefinition(
        [cpqList('country', ['IT', 'CH'], 'IT'), cpqCharacteristic('safety_kit', 'boolean', ['required' => false])],
        [cpqRule('ch-kit', 'force_value', "country == 'CH'", ['target' => 'safety_kit', 'forced_value' => true, 'message' => ['en' => 'Mandatory for CH']])],
    );
    $solver = new ConstraintSolver;

    $swiss = $solver->solve($definition, ['country' => 'CH', 'safety_kit' => false]);
    $italian = $solver->solve($definition, ['country' => 'IT']);

    expect($swiss['values']['safety_kit'])->toBeTrue()
        ->and($swiss['auto_included'])->toHaveCount(1)
        ->and($swiss['auto_included'][0])->toMatchArray(['characteristic' => 'safety_kit', 'value' => true, 'rule' => 'ch-kit', 'message' => 'Mandatory for CH'])
        ->and($italian['values']['safety_kit'])->toBeFalse()
        ->and($italian['auto_included'])->toBe([]);
});

it('drops an automatic inclusion as soon as its condition stops holding, whatever the order of the answers', function (): void {
    $definition = cpqDefinition(
        [cpqList('zone', ['NONE', 'Z1'], 'NONE'), cpqList('motor', ['STD', 'EXD'], 'STD'), cpqCharacteristic('cert', 'boolean', ['required' => false])],
        [
            cpqRule('exd', 'force_value', "zone == 'Z1'", ['target' => 'motor', 'forced_value' => 'EXD']),
            cpqRule('cert-for-exd', 'force_value', "motor == 'EXD'", ['target' => 'cert', 'forced_value' => true, 'priority' => 5]),
        ],
    );
    $solver = new ConstraintSolver;

    $atex = $solver->solve($definition, ['zone' => 'Z1']);
    $safe = $solver->solve($definition, ['zone' => 'NONE']);

    expect($atex['values'])->toMatchArray(['motor' => 'EXD', 'cert' => true])
        ->and($safe['values'])->toMatchArray(['motor' => 'STD', 'cert' => false]);
});

it('hides a characteristic and ignores its answer', function (): void {
    $definition = cpqDefinition(
        [cpqList('fluid', ['WATER', 'CHEM'], 'WATER'), cpqCharacteristic('flush', 'boolean', ['required' => false])],
        [cpqRule('flush-chem', 'hide', "fluid != 'CHEM'", ['target' => 'flush'])],
    );
    $solver = new ConstraintSolver;

    $hidden = $solver->solve($definition, ['fluid' => 'WATER', 'flush' => true]);
    $shown = $solver->solve($definition, ['fluid' => 'CHEM', 'flush' => true]);

    expect($hidden['visible']['flush'])->toBeFalse()
        ->and($hidden['values']['flush'])->toBeFalse()
        ->and($hidden['variables']['flush'])->toBeNull()
        ->and($shown['visible']['flush'])->toBeTrue()
        ->and($shown['values']['flush'])->toBeTrue();
});

it('narrows a numeric range and validates value and step', function (): void {
    $definition = cpqDefinition(
        [cpqList('supply', ['ONE', 'THREE'], 'THREE'), cpqCharacteristic('flow', 'range', ['min' => 5.0, 'max' => 120.0, 'step_size' => 5.0, 'default' => '40'])],
        [cpqRule('one-phase', 'limit_range', "supply == 'ONE'", ['target' => 'flow', 'params' => ['max' => 60]])],
    );
    $solver = new ConstraintSolver;

    expect($solver->solve($definition, ['supply' => 'THREE', 'flow' => 100])['valid'])->toBeTrue();

    $tooHigh = $solver->solve($definition, ['supply' => 'ONE', 'flow' => 100]);
    $offStep = $solver->solve($definition, ['supply' => 'THREE', 'flow' => 42]);
    $tooLow = $solver->solve($definition, ['supply' => 'THREE', 'flow' => 0]);

    expect($tooHigh['limits']['flow'])->toBe(['min' => 5.0, 'max' => 60.0, 'step' => 5.0])
        ->and(cpqViolationCodes($tooHigh))->toBe(['out_of_range'])
        ->and(cpqViolationCodes($offStep))->toBe(['step_mismatch'])
        ->and(cpqViolationCodes($tooLow))->toBe(['out_of_range']);
});

it('derives values with a decision table, first matching row wins, and feeds them to the other rules', function (): void {
    $definition = cpqDefinition(
        [cpqCharacteristic('flow', 'range', ['min' => 1.0, 'max' => 100.0, 'default' => '10']), cpqList('voltage', ['V230', 'V400'], 'V230')],
        [
            cpqRule('sizing', 'decision_table', null, ['params' => ['rows' => [
                ['when' => 'flow <= 10', 'outputs' => ['motor_kw' => 2]],
                ['when' => 'flow <= 50', 'outputs' => ['motor_kw' => 7.5, 'label' => '=flow * 2']],
                ['when' => 'true', 'outputs' => ['motor_kw' => 30]],
            ]]]),
            cpqRule('single-phase', 'exclude_options', 'motor_kw > 2.2', ['target' => 'voltage', 'target_options' => ['V230'], 'message' => ['en' => '{motor_kw} kW is too much for 230 V']]),
        ],
    );
    $solver = new ConstraintSolver;

    $small = $solver->solve($definition, ['flow' => 8, 'voltage' => 'V230']);
    $medium = $solver->solve($definition, ['flow' => 30, 'voltage' => 'V230']);
    $large = $solver->solve($definition, ['flow' => 90, 'voltage' => 'V400']);

    expect($small['derived'])->toBe(['motor_kw' => 2])
        ->and($small['valid'])->toBeTrue()
        ->and($medium['derived'])->toBe(['motor_kw' => 7.5, 'label' => 60.0])
        ->and($medium['valid'])->toBeFalse()
        ->and($medium['disabled']['voltage']['V230'])->toBe(['7.5 kW is too much for 230 V'])
        ->and($large['derived']['motor_kw'])->toBe(30)
        ->and($large['valid'])->toBeTrue();
});

it('reports a rule whose condition is wrong without breaking the evaluation', function (): void {
    $definition = cpqDefinition([cpqList('a', ['X'], 'X')], [cpqRule('broken', 'validate', 'a == ')]);

    $solution = (new ConstraintSolver)->solve($definition, []);

    expect(cpqViolationCodes($solution))->toBe(['rule_error'])
        ->and($solution['violations'][0]['rule'])->toBe('broken');
});

it('raises validate rules with their severity and only errors make the configuration invalid', function (): void {
    $definition = cpqDefinition([cpqList('a', ['X', 'Y'], 'X')], [
        cpqRule('warn', 'validate', "a == 'X'", ['severity' => 'warning', 'message' => ['en' => 'Careful']]),
        cpqRule('stop', 'validate', "a == 'Y'", ['message' => ['en' => 'Not possible']]),
    ]);
    $solver = new ConstraintSolver;

    $warning = $solver->solve($definition, ['a' => 'X']);
    $error = $solver->solve($definition, ['a' => 'Y']);

    expect($warning['valid'])->toBeTrue()
        ->and($warning['violations'][0]['severity'])->toBe('warning')
        ->and($warning['violations'][0]['message'])->toBe('Careful')
        ->and($error['valid'])->toBeFalse();
});

it('rejects an unknown or inactive option and a value of the wrong type', function (): void {
    $inactive = cpqList('a', ['X', 'Y'], 'X');
    $inactive['options'][1]['active'] = false;
    $definition = cpqDefinition([$inactive, cpqCharacteristic('n', 'range', ['min' => 1.0, 'max' => 5.0, 'required' => false])]);
    $solver = new ConstraintSolver;

    expect(cpqViolationCodes($solver->solve($definition, ['a' => 'Z'])))->toBe(['unknown_option'])
        ->and(cpqViolationCodes($solver->solve($definition, ['a' => 'Y'])))->toBe(['unknown_option'])
        ->and(cpqViolationCodes($solver->solve($definition, ['a' => 'X', 'n' => 'abc'])))->toBe(['invalid_value']);
});

it('is deterministic: the same answers always give the same solution', function (): void {
    $definition = cpqDefinition(
        [cpqList('country', ['IT', 'CH'], 'IT'), cpqCharacteristic('kit', 'boolean', ['required' => false])],
        [cpqRule('kit', 'force_value', "country == 'CH'", ['target' => 'kit', 'forced_value' => true])],
    );
    $solver = new ConstraintSolver;

    expect($solver->solve($definition, ['country' => 'CH']))->toBe($solver->solve($definition, ['country' => 'CH']));
});

it('stops a set of rules that never settles', function (): void {
    $definition = cpqDefinition(
        [cpqCharacteristic('flip', 'boolean', ['required' => false])],
        [cpqRule('toggle', 'force_value', 'not flip', ['target' => 'flip', 'forced_value' => true]), cpqRule('untoggle', 'force_value', 'flip', ['target' => 'flip', 'forced_value' => false, 'priority' => 1])],
    );

    $solution = (new ConstraintSolver)->solve($definition, []);

    expect(cpqViolationCodes($solution))->toContain('unstable_rules')
        ->and($solution['valid'])->toBeFalse();
});

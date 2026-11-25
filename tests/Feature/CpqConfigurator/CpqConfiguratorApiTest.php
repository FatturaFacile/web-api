<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Models\CpqModel;

require_once __DIR__.'/CpqConfiguratorSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    cpqSetUp($this);
});

it('requires authentication', function (): void {
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson(cpqUrl('/models'))->assertUnauthorized();
});

it('lets every member read and evaluate, only the sales roles confirm and only the managers define', function (string $role, int $list, int $evaluate, int $confirm, int $create): void {
    cpqActor($this, $role);
    $modelId = cpqModel($this)->id;
    $payload = ['selections' => cpqPumpSelections()];

    $this->getJson(cpqUrl('/models'))->assertStatus($list);
    $this->postJson(cpqUrl("/models/{$modelId}/evaluate"), $payload)->assertStatus($evaluate);
    $this->postJson(cpqUrl("/models/{$modelId}/confirm"), $payload)->assertStatus($confirm);
    $this->postJson(cpqUrl('/models'), ['code' => 'NEW-'.$role, 'name' => ['it' => 'Nuovo'], 'part_number_prefix' => 'NW'])->assertStatus($create);
})->with([
    'owner' => ['owner', 200, 200, 201, 201],
    'sales manager' => ['sales_manager', 200, 200, 201, 201],
    'sales' => ['sales', 200, 200, 201, 403],
    'accountant' => ['accountant', 200, 200, 201, 403],
    'plain member' => ['member', 200, 200, 403, 403],
]);

it('evaluates a selection with constraints, super-BOM, routing and the totals in one call', function (): void {
    $response = $this->postJson(cpqUrl('/models/'.cpqModel($this)->id.'/evaluate'), ['selections' => cpqPumpSelections(), 'quantity' => 1])->assertOk();

    $data = $response->json('data');

    expect($data['valid'])->toBeTrue()
        ->and($data['complete'])->toBeTrue()
        ->and(array_column($data['bom'], 'item_code'))->toContain('SKID-FRAME', 'PUMP-BODY-304', 'MOTOR-IE3', 'PANEL-DOL', 'CABLE-4G')
        ->and(array_column($data['routing'], 'work_center'))->toContain('WC-ASM', 'WC-WLD', 'WC-ELE', 'WC-TST')
        ->and(array_keys($data['totals']))->toContain('industrial_cost', 'list_price', 'selling_price', 'margin_amount', 'margin_percent', 'lead_time_days')
        ->and($data['totals']['industrial_cost'])->toBeGreaterThan(0)
        ->and((float) $data['totals']['selling_price'])->toBeGreaterThan((float) $data['totals']['industrial_cost'])
        ->and($data['configuration_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($data['part_number_preview'])->toStartWith('PSK-S4-400-B-Q40-H30')
        ->and($data['steps'])->toHaveCount(4)
        ->and($data['visual']['layers'])->not->toBeEmpty();
});

it('includes the SUVA/ESTI safety kit automatically for Switzerland and explains why', function (): void {
    $data = $this->postJson(cpqUrl('/models/'.cpqModel($this)->id.'/evaluate'), ['selections' => cpqPumpSelections(['destination_country' => 'CH'])])->assertOk()->json('data');

    expect($data['auto_included'])->toHaveCount(1)
        ->and($data['auto_included'][0]['characteristic'])->toBe('safety_kit')
        ->and($data['auto_included'][0]['message'])->toContain('SUVA/ESTI')
        ->and(array_column($data['bom'], 'item_code'))->toContain('KIT-SAFETY', 'LABEL-ESTI')
        ->and(collect($data['visual']['layers'])->firstWhere('characteristic', 'safety_kit')['auto_included'])->toBeTrue();
});

it('blocks AISI 304 for aggressive chemicals, single-phase for big motors, and says why in the language of the request', function (): void {
    $selections = cpqPumpSelections(['fluid' => 'CHEM', 'flow_rate' => 60, 'head' => 60, 'voltage' => 'V230_1P']);

    $english = $this->postJson(cpqUrl('/models/'.cpqModel($this)->id.'/evaluate'), ['selections' => $selections])->assertOk()->json('data');
    $italian = $this->withHeader('Accept-Language', 'it')->postJson(cpqUrl('/models/'.cpqModel($this)->id.'/evaluate'), ['selections' => $selections])->assertOk()->json('data');

    $material = collect($english['characteristics'])->firstWhere('code', 'material');
    $voltage = collect($english['characteristics'])->firstWhere('code', 'voltage');
    $disabled = collect($material['options'])->firstWhere('code', 'AISI_304');

    expect($english['valid'])->toBeFalse()
        ->and($disabled['disabled'])->toBeTrue()
        ->and($disabled['reasons'][0])->toContain('AISI 316')
        ->and(collect($voltage['options'])->firstWhere('code', 'V230_1P')['disabled'])->toBeTrue()
        ->and(collect($english['violations'])->pluck('code')->all())->toContain('option_blocked')
        ->and(collect($italian['characteristics'])->firstWhere('code', 'material')['label'])->toBe('Materiale parti bagnate');
});

it('narrows ranges and hides characteristics with the rules', function (): void {
    $data = $this->postJson(cpqUrl('/models/'.cpqModel($this)->id.'/evaluate'), ['selections' => cpqPumpSelections(['voltage' => 'V230_1P', 'flow_rate' => 30, 'head' => 20])])->assertOk()->json('data');

    $flow = collect($data['characteristics'])->firstWhere('code', 'flow_rate');
    $flush = collect($data['characteristics'])->firstWhere('code', 'flush_kit');

    expect($flow['max'])->toEqual(60)
        ->and($flush['visible'])->toBeFalse();
});

it('hides cost and margin from who may not see them and shows them to managers and accountants', function (string $role, bool $sees): void {
    cpqActor($this, $role);

    $totals = $this->postJson(cpqUrl('/models/'.cpqModel($this)->id.'/evaluate'), ['selections' => cpqPumpSelections()])->assertOk()->json('data.totals');
    $bom = $this->postJson(cpqUrl('/models/'.cpqModel($this)->id.'/evaluate'), ['selections' => cpqPumpSelections()])->json('data.bom.0');

    expect(array_key_exists('industrial_cost', $totals))->toBe($sees)
        ->and(array_key_exists('margin_percent', $totals))->toBe($sees)
        ->and(array_key_exists('line_cost', $bom))->toBe($sees)
        ->and($totals['selling_price'])->not->toBeNull()
        ->and($totals['lead_time_days'])->toBeGreaterThan(0);
})->with([
    'owner' => ['owner', true],
    'sales manager' => ['sales_manager', true],
    'accountant' => ['accountant', true],
    'sales' => ['sales', false],
    'member' => ['member', false],
]);

it('rejects an invalid selection payload', function (): void {
    $id = cpqModel($this)->id;

    $this->postJson(cpqUrl("/models/{$id}/evaluate"), [])->assertUnprocessable()->assertJsonValidationErrors(['selections']);
    $this->postJson(cpqUrl("/models/{$id}/evaluate"), ['selections' => [], 'quantity' => 0])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    $this->postJson(cpqUrl("/models/{$id}/evaluate"), ['selections' => [], 'discount_percent' => 120])->assertUnprocessable()->assertJsonValidationErrors(['discount_percent']);
});

it('shows only the published models to the sales staff and every model to the managers', function (): void {
    $draftId = $this->postJson(cpqUrl('/models'), ['code' => 'DRAFT-1', 'name' => ['it' => 'Bozza'], 'part_number_prefix' => 'DR'])->assertCreated()->json('data.id');

    expect($this->getJson(cpqUrl('/models'))->json('data'))->toHaveCount(3);

    cpqActor($this, 'sales');

    expect(collect($this->getJson(cpqUrl('/models'))->json('data'))->pluck('code')->all())->toBe(['ELEC-PANEL', 'PUMP-SKID']);
    $this->getJson(cpqUrl("/models/{$draftId}"))->assertNotFound();
    $this->postJson(cpqUrl("/models/{$draftId}/evaluate"), ['selections' => []])->assertNotFound();
});

it('manages a model end to end: characteristic, options, rule, mappings, formula, diagnostics, publication', function (): void {
    $modelId = $this->postJson(cpqUrl('/models'), ['code' => 'bench', 'name' => ['it' => 'Banco', 'en' => 'Bench'], 'part_number_prefix' => 'bn', 'base_cost' => 10, 'target_margin_percent' => 25])->assertCreated()->json('data.id');
    $characteristicId = $this->postJson(cpqUrl("/models/{$modelId}/characteristics"), ['code' => 'size', 'type' => 'list', 'label' => ['it' => 'Taglia', 'en' => 'Size'], 'part_number' => ['position' => 1]])->assertCreated()->json('data.id');
    $this->postJson(cpqUrl("/characteristics/{$characteristicId}/options"), ['code' => 'S', 'label' => ['it' => 'Piccola', 'en' => 'Small'], 'part_number_code' => 'S', 'is_default' => true])->assertCreated();
    $second = $this->postJson(cpqUrl("/characteristics/{$characteristicId}/options"), ['code' => 'L', 'label' => ['it' => 'Grande', 'en' => 'Large'], 'part_number_code' => 'L', 'is_default' => true])->assertCreated()->json('data.id');
    $this->postJson(cpqUrl("/models/{$modelId}/characteristics"), ['code' => 'length', 'type' => 'range', 'label' => ['it' => 'Lunghezza'], 'range_min' => 1, 'range_max' => 10, 'range_step' => 1, 'default_value' => '2'])->assertCreated();
    $this->postJson(cpqUrl("/models/{$modelId}/rules"), ['code' => 'no-large-long', 'rule_type' => 'validate', 'condition_expression' => "size == 'L' and length > 5", 'message' => ['it' => 'Troppo lungo', 'en' => 'Too long']])->assertCreated();
    $this->postJson(cpqUrl("/models/{$modelId}/superbom-mappings"), ['code' => 'tube', 'item_code' => 'TUBE', 'item_name' => ['it' => 'Tubo'], 'quantity_formula' => 'length * 2', 'unit_cost' => 3])->assertCreated();
    $this->postJson(cpqUrl("/models/{$modelId}/routing-mappings"), ['code' => 'cut', 'operation_name' => ['it' => 'Taglio'], 'work_center' => 'WC-CUT', 'run_hours_formula' => '0.2 * length', 'hourly_rate' => 40])->assertCreated();
    $this->postJson(cpqUrl("/models/{$modelId}/pricing-formulas"), ['code' => 'fee', 'label' => ['it' => 'Fee'], 'effect' => 'add_price', 'formula' => '25'])->assertCreated();

    $this->getJson(cpqUrl("/models/{$modelId}/diagnostics"))->assertOk()->assertJsonPath('data.ok', true);
    $this->putJson(cpqUrl("/options/{$second}"), ['is_default' => false])->assertOk();

    $this->postJson(cpqUrl("/models/{$modelId}/publish"))->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.version', 2);

    $shown = $this->getJson(cpqUrl("/models/{$modelId}"))->assertOk()->json('data');
    expect($shown['code'])->toBe('BENCH')
        ->and($shown['part_number_prefix'])->toBe('BN')
        ->and($shown['characteristics'])->toHaveCount(2)
        ->and($shown['characteristics'][1]['options'])->toHaveCount(2)
        ->and($shown['rules'])->toHaveCount(1)
        ->and($shown['bom_mappings'])->toHaveCount(1)
        ->and($shown['routing_mappings'])->toHaveCount(1)
        ->and($shown['pricing_formulas'])->toHaveCount(1);

    $evaluation = $this->postJson(cpqUrl("/models/{$modelId}/evaluate"), ['selections' => ['size' => 'L', 'length' => 8]])->assertOk()->json('data');
    expect($evaluation['valid'])->toBeFalse()
        ->and($evaluation['violations'][0]['message'])->toBe('Too long')
        // 10 base cost + 16 x 3 tube + (1.6 h x 40) cutting; the only price component is the 25 fee
        ->and($evaluation['totals']['industrial_cost'])->toEqual(122)
        ->and($evaluation['totals']['list_price'])->toEqual(25)
        ->and($evaluation['totals']['lead_time_days'])->toBe(1);
});

it('raises the version of a published model with every change so a hash never outlives its rules', function (): void {
    $model = cpqModel($this);
    $version = $model->version;

    $this->postJson(cpqUrl("/models/{$model->id}/pricing-formulas"), ['code' => 'extra', 'label' => ['it' => 'Extra'], 'effect' => 'add_price', 'formula' => '10'])->assertCreated();
    $this->putJson(cpqUrl("/models/{$model->id}"), ['base_cost' => 400])->assertOk()->assertJsonPath('data.version', $version + 2);

    expect(CpqModel::query()->findOrFail($model->id)->version)->toBe($version + 2);
});

it('refuses to publish a model whose expressions do not parse or point at unknown characteristics', function (): void {
    $modelId = $this->postJson(cpqUrl('/models'), ['code' => 'BROKEN', 'name' => ['it' => 'Rotto'], 'part_number_prefix' => 'BR'])->assertCreated()->json('data.id');
    $this->postJson(cpqUrl("/models/{$modelId}/characteristics"), ['code' => 'a', 'type' => 'boolean', 'label' => ['it' => 'A']])->assertCreated();
    $this->postJson(cpqUrl("/models/{$modelId}/rules"), ['code' => 'r1', 'rule_type' => 'validate', 'condition_expression' => 'a ==', 'message' => ['it' => 'x']])->assertCreated();
    $this->postJson(cpqUrl("/models/{$modelId}/superbom-mappings"), ['code' => 'b1', 'item_code' => 'X', 'item_name' => ['it' => 'X'], 'quantity_formula' => 'lenght * 2'])->assertCreated();
    $this->postJson(cpqUrl("/models/{$modelId}/rules"), ['code' => 'r2', 'rule_type' => 'force_value', 'target_characteristic' => 'ghost', 'forced_value' => true])->assertCreated();

    $issues = collect($this->getJson(cpqUrl("/models/{$modelId}/diagnostics"))->assertOk()->json('data.issues'));

    expect($issues->pluck('code')->all())->toContain('syntax_error', 'unknown_variable', 'unknown_target')
        ->and($issues->firstWhere('code', 'unknown_variable')['params']['variable'])->toBe('lenght');
    $this->postJson(cpqUrl("/models/{$modelId}/publish"))->assertUnprocessable();
    expect(CpqModel::query()->findOrFail($modelId)->status)->toBe('draft');
});

it('checks an expression against the characteristics of the model', function (): void {
    $id = cpqModel($this)->id;

    $this->postJson(cpqUrl("/models/{$id}/expression-check"), ['expression' => "flow_rate * head > 100 and fluid == 'CHEM'"])->assertOk()
        ->assertJsonPath('data.valid', true)->assertJsonPath('data.unknown', []);
    $this->postJson(cpqUrl("/models/{$id}/expression-check"), ['expression' => 'flow_rate * heed'])->assertOk()->assertJsonPath('data.unknown', ['heed']);
    $this->postJson(cpqUrl("/models/{$id}/expression-check"), ['expression' => '1 +'])->assertOk()->assertJsonPath('data.valid', false);
    $this->postJson(cpqUrl("/models/{$id}/expression-check"), ['expression' => 'motor_kw * 2'])->assertOk()->assertJsonPath('data.unknown', []);
});

it('validates definition payloads: unique codes, known rule types, effects and characteristic types', function (): void {
    $id = cpqModel($this)->id;
    $rule = ['rule_type' => 'validate', 'message' => ['it' => 'x']];

    $this->postJson(cpqUrl('/models'), ['code' => 'pump-skid', 'name' => ['it' => 'Doppio'], 'part_number_prefix' => 'PS'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson(cpqUrl("/models/{$id}/characteristics"), ['code' => 'fluid', 'type' => 'list', 'label' => ['it' => 'Doppio']])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson(cpqUrl("/models/{$id}/characteristics"), ['code' => 'weird', 'type' => 'matrix', 'label' => ['it' => 'x']])->assertUnprocessable()->assertJsonValidationErrors(['type']);
    $this->postJson(cpqUrl("/models/{$id}/rules"), ['code' => 'motor-sizing'] + $rule)->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson(cpqUrl("/models/{$id}/rules"), ['code' => 'new-rule', 'rule_type' => 'teleport'])->assertUnprocessable()->assertJsonValidationErrors(['rule_type']);
    $this->postJson(cpqUrl("/models/{$id}/pricing-formulas"), ['code' => 'f', 'label' => ['it' => 'f'], 'effect' => 'give_away', 'formula' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['effect']);
    $this->postJson(cpqUrl("/models/{$id}/characteristics"), ['code' => 'Bad Code', 'type' => 'list', 'label' => ['it' => 'x']])->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

it('refuses options on a characteristic that is not a value list', function (): void {
    $range = cpqInTenant($this->tenant, fn () => CpqCharacteristic::query()->where('code', 'flow_rate')->firstOrFail());

    $this->postJson(cpqUrl("/characteristics/{$range->id}/options"), ['code' => 'X', 'label' => ['it' => 'x']])->assertUnprocessable();
});

it('keeps one default option per characteristic', function (): void {
    $characteristic = cpqInTenant($this->tenant, fn () => CpqCharacteristic::query()->where('code', 'material')->firstOrFail());
    $newId = $this->postJson(cpqUrl("/characteristics/{$characteristic->id}/options"), ['code' => 'DUPLEX', 'label' => ['it' => 'Duplex'], 'is_default' => true])->assertCreated()->json('data.id');

    $defaults = cpqInTenant($this->tenant, fn () => $characteristic->options()->where('is_default', true)->pluck('id')->all());

    expect($defaults)->toBe([$newId]);
});

it('archives a model and keeps it away from the configurator; deletes only a model without instances', function (): void {
    $model = cpqModel($this);
    $this->postJson(cpqUrl("/models/{$model->id}/confirm"), ['selections' => cpqPumpSelections()])->assertCreated();

    $this->deleteJson(cpqUrl("/models/{$model->id}"))->assertUnprocessable();
    $this->postJson(cpqUrl("/models/{$model->id}/archive"))->assertOk()->assertJsonPath('data.status', 'archived');
    $this->postJson(cpqUrl("/models/{$model->id}/evaluate"), ['selections' => []])->assertNotFound();
    $this->putJson(cpqUrl("/models/{$model->id}"), ['base_cost' => 1])->assertUnprocessable();

    $panel = cpqModel($this, 'ELEC-PANEL');
    $this->deleteJson(cpqUrl("/models/{$panel->id}"))->assertNoContent();
    expect(CpqModel::query()->where('code', 'ELEC-PANEL')->exists())->toBeFalse()
        ->and(CpqConfiguredInstance::query()->count())->toBe(1);
});

it('deletes a characteristic with its options and a mapping', function (): void {
    $flush = cpqInTenant($this->tenant, fn () => CpqCharacteristic::query()->where('code', 'test_level')->firstOrFail());

    $this->deleteJson(cpqUrl("/characteristics/{$flush->id}"))->assertNoContent();

    expect(cpqInTenant($this->tenant, fn () => CpqCharacteristic::query()->where('code', 'test_level')->exists()))->toBeFalse();
});

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;

require_once __DIR__.'/CpqConfiguratorSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    cpqSetUp($this, 'sales_manager');
    $this->modelId = cpqModel($this)->id;
});

function cpqConfirm(object $test, array $selections, array $extra = []): TestResponse
{
    return $test->postJson(cpqUrl("/models/{$test->modelId}/confirm"), ['selections' => $selections] + $extra);
}

it('issues a smart part number for a new configuration and stores the resolved snapshot', function (): void {
    $response = cpqConfirm($this, cpqPumpSelections(['destination_country' => 'CH']), ['customer_reference' => 'ACME-77'])->assertCreated()->assertJsonPath('meta.reused', false);

    $data = $response->json('data');

    expect($data['part_number'])->toBe('PSK-S4-400-B-Q40-H30-SK-CH-0001')
        ->and($data['configuration_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($data['status'])->toBe('confirmed')
        ->and($data['customer_reference'])->toBe('ACME-77')
        ->and($data['reuse_count'])->toBe(0)
        ->and($data['model_version'])->toBe(1)
        ->and($data['configuration']['values'])->toMatchArray(['destination_country' => 'CH', 'safety_kit' => 'true', 'flow_rate' => '40'])
        ->and(array_column($data['snapshot']['bom'], 'item_code'))->toContain('KIT-SAFETY')
        ->and($data['snapshot']['routing'])->not->toBeEmpty()
        ->and((float) $data['industrial_cost'])->toBeGreaterThan(0)
        ->and((float) $data['selling_price'])->toBeGreaterThan((float) $data['industrial_cost'])
        ->and($data['lead_time_days'])->toBeGreaterThan(0);
});

it('reuses the part number of an identical configuration instead of creating a new one', function (): void {
    $first = cpqConfirm($this, cpqPumpSelections())->assertCreated()->json('data');
    $second = cpqConfirm($this, array_reverse(cpqPumpSelections(), true))->assertOk()->assertJsonPath('meta.reused', true)->json('data');
    $third = cpqConfirm($this, cpqPumpSelections(['flow_rate' => 40.0, 'head' => '30']), ['quantity' => 3, 'discount_percent' => 5])->assertOk()->assertJsonPath('meta.reused', true)->json('data');

    expect($second['id'])->toBe($first['id'])
        ->and($second['part_number'])->toBe($first['part_number'])
        ->and($third['id'])->toBe($first['id'])
        ->and($third['reuse_count'])->toBe(2)
        ->and(CpqConfiguredInstance::query()->count())->toBe(1)
        ->and(MfgItem::query()->where('item_code', $first['part_number'])->count())->toBe(1)
        ->and(MfgBom::query()->where('bom_number', $first['part_number'])->count())->toBe(1);
});

it('gives a new part number as soon as a characteristic changes, with a running sequence', function (): void {
    $a = cpqConfirm($this, cpqPumpSelections())->assertCreated()->json('data.part_number');
    $b = cpqConfirm($this, cpqPumpSelections(['flow_rate' => 45]))->assertCreated()->json('data.part_number');
    $c = cpqConfirm($this, cpqPumpSelections(['material' => 'AISI_316']))->assertCreated()->json('data.part_number');

    expect($a)->toEndWith('-0001')
        ->and($b)->toEndWith('-0002')->and($b)->toContain('Q45')
        ->and($c)->toEndWith('-0003')->and($c)->toStartWith('PSK-S6')
        ->and(CpqModel::query()->findOrFail($this->modelId)->part_number_sequence)->toBe(3);
});

it('ignores the answers of hidden characteristics: they do not change the hash', function (): void {
    $first = cpqConfirm($this, cpqPumpSelections(['flush_kit' => false]))->assertCreated()->json('data');
    $second = cpqConfirm($this, cpqPumpSelections(['flush_kit' => true]))->assertOk()->json('data');

    expect($second['id'])->toBe($first['id']);
});

it('does not reuse a part number after the rules of the model changed', function (): void {
    $first = cpqConfirm($this, cpqPumpSelections())->assertCreated()->json('data');
    $this->postJson(cpqUrl("/models/{$this->modelId}/pricing-formulas"), ['code' => 'new-fee', 'label' => ['it' => 'Nuova voce'], 'effect' => 'add_price', 'formula' => '10'])->assertCreated();
    $second = cpqConfirm($this, cpqPumpSelections())->assertCreated()->json('data');

    expect($second['id'])->not->toBe($first['id'])
        ->and($second['part_number'])->not->toBe($first['part_number'])
        ->and($second['model_version'])->toBe($first['model_version'] + 1)
        ->and($second['configuration_hash'])->not->toBe($first['configuration_hash']);
});

it('materialises the configuration as a finished-good item with a draft BOM', function (): void {
    $partNumber = cpqConfirm($this, cpqPumpSelections())->assertCreated()->json('data.part_number');

    $item = MfgItem::query()->where('item_code', $partNumber)->firstOrFail();
    $bom = MfgBom::query()->where('mfg_item_id', $item->id)->firstOrFail();
    $components = MfgBomComponent::query()->where('bom_id', $bom->id)->get();
    $componentCodes = MfgItem::query()->whereIn('id', $components->pluck('component_item_id'))->pluck('item_code')->all();

    expect($item->item_type)->toBe('finished_good')
        ->and($bom->bom_number)->toBe($partNumber)
        ->and($bom->status)->toBe('draft')
        ->and($componentCodes)->toContain('SKID-FRAME', 'PUMP-BODY-304', 'MOTOR-IE3', 'PANEL-DOL', 'CABLE-4G')
        // 10 m of cable plus 5% for the cuts
        ->and($components->firstWhere('notes', 'cable')->quantity_per)->toEqual('10.5000');

    $instance = CpqConfiguredInstance::query()->firstOrFail();

    expect($instance->mfg_item_id)->toBe($item->id)->and($instance->mfg_bom_id)->toBe($bom->id);
});

it('does not confirm an invalid configuration', function (): void {
    cpqConfirm($this, cpqPumpSelections(['fluid' => 'CHEM']))->assertUnprocessable()->assertJsonValidationErrors(['selections']);
    cpqConfirm($this, cpqPumpSelections(['flow_rate' => 42]))->assertUnprocessable();
    cpqConfirm($this, cpqPumpSelections(['material' => 'DIAMOND']))->assertUnprocessable();

    expect(CpqConfiguredInstance::query()->count())->toBe(0)
        ->and(CpqModel::query()->findOrFail($this->modelId)->part_number_sequence)->toBe(0)
        ->and(MfgItem::query()->count())->toBe(0);
});

it('does not confirm an incomplete configuration: a required characteristic without answer or default', function (): void {
    $id = $this->postJson(cpqUrl('/models'), ['code' => 'INCOMPLETE', 'name' => ['it' => 'Incompleto'], 'part_number_prefix' => 'IN'])->assertCreated()->json('data.id');
    $characteristic = $this->postJson(cpqUrl("/models/{$id}/characteristics"), ['code' => 'size', 'type' => 'list', 'label' => ['it' => 'Taglia'], 'part_number' => ['position' => 1]])->assertCreated()->json('data.id');
    $this->postJson(cpqUrl("/characteristics/{$characteristic}/options"), ['code' => 'S', 'label' => ['it' => 'S'], 'part_number_code' => 'S'])->assertCreated();
    $this->postJson(cpqUrl("/models/{$id}/publish"))->assertOk();

    $open = $this->postJson(cpqUrl("/models/{$id}/evaluate"), ['selections' => []])->assertOk()->json('data');
    $this->postJson(cpqUrl("/models/{$id}/confirm"), ['selections' => []])->assertUnprocessable();
    $this->postJson(cpqUrl("/models/{$id}/confirm"), ['selections' => ['size' => 'S']])->assertCreated()->assertJsonPath('data.part_number', 'IN-S-0001');

    expect($open['valid'])->toBeTrue()
        ->and($open['complete'])->toBeFalse()
        ->and($open['missing'])->toBe(['size'])
        ->and($open['steps'][0]['status'])->toBe('incomplete');
});

it('does not confirm with a model that is not published', function (): void {
    $draftId = $this->postJson(cpqUrl('/models'), ['code' => 'DRAFT', 'name' => ['it' => 'Bozza'], 'part_number_prefix' => 'DR'])->assertCreated()->json('data.id');

    $this->postJson(cpqUrl("/models/{$draftId}/confirm"), ['selections' => []])->assertUnprocessable();
});

it('lists and shows the instances and hides the costs from the sales staff', function (): void {
    $id = cpqConfirm($this, cpqPumpSelections())->assertCreated()->json('data.id');
    cpqConfirm($this, cpqPumpSelections(['flow_rate' => 45]))->assertCreated();

    $manager = $this->getJson(cpqUrl('/instances'))->assertOk()->json('data');
    $filtered = $this->getJson(cpqUrl('/instances?search=Q45'))->assertOk()->json('data');
    expect($manager)->toHaveCount(2)
        ->and($filtered)->toHaveCount(1)
        ->and(array_key_exists('industrial_cost', $manager[0]))->toBeTrue();

    cpqActor($this, 'sales');
    $sales = $this->getJson(cpqUrl("/instances/{$id}"))->assertOk()->json('data');

    expect(array_key_exists('industrial_cost', $sales))->toBeFalse()
        ->and(array_key_exists('margin_percent', $sales))->toBeFalse()
        ->and(array_key_exists('line_cost', $sales['snapshot']['bom'][0]))->toBeFalse()
        ->and(array_key_exists('industrial_cost', $sales['snapshot']['totals']))->toBeFalse()
        ->and($sales['selling_price'])->not->toBeNull();
});

it('builds the technical-commercial proposal of a selection that is not saved', function (): void {
    $dossier = $this->postJson(cpqUrl("/models/{$this->modelId}/proposal"), ['selections' => cpqPumpSelections(['destination_country' => 'CH']), 'quantity' => 2, 'customer_reference' => 'ACME', 'include_breakdown' => true])->assertOk()->json('data');

    expect($dossier['dossier']['product']['model_code'])->toBe('PUMP-SKID')
        ->and($dossier['dossier']['document']['confirmed'])->toBeFalse()
        ->and($dossier['dossier']['document']['customer'])->toBe('ACME')
        ->and($dossier['dossier']['specification'])->not->toBeEmpty()
        ->and($dossier['dossier']['auto_included'])->toHaveCount(1)
        ->and($dossier['dossier']['texts']['warranty'])->toContain('Warranty')
        ->and($dossier['dossier']['dimensional_notes'])->toHaveCount(2)
        ->and($dossier['dossier']['commercial']['quantity'])->toEqual(2)
        ->and($dossier['dossier']['commercial']['lead_time_days'])->toBeGreaterThan(0)
        ->and($dossier['dossier']['breakdown']['industrial_cost'])->toBeGreaterThan(0)
        ->and($dossier['html'])->toContain('Technical and commercial proposal')->toContain('ACME')->not->toContain('<script');
});

it('gives the proposal of a confirmed instance in JSON and as a PDF, in the language of the request', function (): void {
    $id = cpqConfirm($this, cpqPumpSelections())->assertCreated()->json('data.id');

    $italian = $this->withHeader('Accept-Language', 'it')->getJson(cpqUrl("/instances/{$id}/proposal?quantity=3&include_breakdown=1"))->assertOk()->json('data');
    $pdf = $this->get(cpqUrl("/instances/{$id}/proposal/pdf"));

    expect($italian['dossier']['document']['confirmed'])->toBeTrue()
        ->and($italian['dossier']['document']['reference'])->toStartWith('PSK-S4-400-B')
        ->and($italian['dossier']['texts']['title'])->toBe('Proposta tecnico-commerciale — gruppo pompa')
        ->and($italian['dossier']['commercial']['quantity'])->toEqual(3)
        ->and($italian['dossier']['breakdown'])->not->toBeNull()
        ->and($italian['html'])->toContain('Specifica tecnica della variante');

    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-');
});

it('shows the industrial breakdown of a proposal only to who may see the costs', function (): void {
    $id = cpqConfirm($this, cpqPumpSelections())->assertCreated()->json('data.id');
    cpqActor($this, 'sales');

    $dossier = $this->getJson(cpqUrl("/instances/{$id}/proposal?include_breakdown=1"))->assertOk()->json('data.dossier');
    $preview = $this->postJson(cpqUrl("/models/{$this->modelId}/proposal"), ['selections' => cpqPumpSelections(), 'include_breakdown' => true])->assertOk()->json('data');

    expect($dossier['breakdown'])->toBeNull()
        ->and($preview['dossier']['breakdown'])->toBeNull()
        ->and($preview['html'])->not->toContain('Industrial breakdown');
});

it('escapes everything it prints in the proposal', function (): void {
    $id = cpqConfirm($this, cpqPumpSelections())->assertCreated()->json('data.id');

    $html = $this->getJson(cpqUrl("/instances/{$id}/proposal?customer_reference=".urlencode('<script>alert(1)</script>')))->assertOk()->json('data.html');

    expect($html)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;');
});

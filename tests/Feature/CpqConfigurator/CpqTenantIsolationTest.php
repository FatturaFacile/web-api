<?php

declare(strict_types=1);

use Database\Seeders\CpqConfiguratorDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Models\CpqModel;

require_once __DIR__.'/CpqConfiguratorSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    cpqSetUp($this, 'owner');
    $this->otherTenant = cpqTenant('Altra Azienda Srl');
    cpqInTenant($this->otherTenant, fn () => (new CpqConfiguratorDemoSeeder)->seedTenant());
    $this->modelId = cpqModel($this)->id;
    $this->otherModelId = cpqInTenant($this->otherTenant, fn () => CpqModel::query()->where('code', 'PUMP-SKID')->firstOrFail()->id);
    $this->instanceId = $this->postJson(cpqUrl("/models/{$this->modelId}/confirm"), ['selections' => cpqPumpSelections()])->assertCreated()->json('data.id');
});

it('keeps the models of each company apart', function (): void {
    expect($this->modelId)->not->toBe($this->otherModelId)
        ->and(cpqInTenant($this->tenant, fn () => CpqModel::query()->count()))->toBe(2)
        ->and(cpqInTenant($this->otherTenant, fn () => CpqModel::query()->count()))->toBe(2)
        ->and(CpqModel::query()->count())->toBe(2);

    $codes = collect($this->getJson(cpqUrl('/models'))->json('data'))->pluck('id');
    expect($codes->contains($this->otherModelId))->toBeFalse();
});

it('answers 404 for a model, characteristic or instance of another company, on every verb', function (): void {
    $otherCharacteristic = cpqInTenant($this->otherTenant, fn () => CpqCharacteristic::query()->where('code', 'material')->firstOrFail()->id);
    $otherInstance = cpqInTenant($this->otherTenant, function (): string {
        return CpqConfiguredInstance::query()->count() === 0 ? 'none' : 'some';
    });

    expect($otherInstance)->toBe('none');

    $this->getJson(cpqUrl("/models/{$this->otherModelId}"))->assertNotFound();
    $this->putJson(cpqUrl("/models/{$this->otherModelId}"), ['base_cost' => 1])->assertNotFound();
    $this->deleteJson(cpqUrl("/models/{$this->otherModelId}"))->assertNotFound();
    $this->postJson(cpqUrl("/models/{$this->otherModelId}/publish"))->assertNotFound();
    $this->postJson(cpqUrl("/models/{$this->otherModelId}/evaluate"), ['selections' => cpqPumpSelections()])->assertNotFound();
    $this->postJson(cpqUrl("/models/{$this->otherModelId}/confirm"), ['selections' => cpqPumpSelections()])->assertNotFound();
    $this->postJson(cpqUrl("/models/{$this->otherModelId}/proposal"), ['selections' => cpqPumpSelections()])->assertNotFound();
    $this->getJson(cpqUrl("/models/{$this->otherModelId}/rules"))->assertNotFound();
    $this->getJson(cpqUrl("/models/{$this->otherModelId}/diagnostics"))->assertNotFound();
    $this->putJson(cpqUrl("/characteristics/{$otherCharacteristic}"), ['label' => ['it' => 'Hack']])->assertNotFound();
    $this->postJson(cpqUrl("/characteristics/{$otherCharacteristic}/options"), ['code' => 'HACK', 'label' => ['it' => 'x']])->assertNotFound();
});

it('does not let one company read, print or reuse the instances of another', function (): void {
    cpqActor($this, 'owner', $this->otherTenant);

    $this->getJson(cpqUrl("/instances/{$this->instanceId}"))->assertNotFound();
    $this->getJson(cpqUrl("/instances/{$this->instanceId}/proposal"))->assertNotFound();
    $this->getJson(cpqUrl("/instances/{$this->instanceId}/proposal/pdf"))->assertNotFound();
    expect($this->getJson(cpqUrl('/instances'))->assertOk()->json('data'))->toBe([]);

    $mine = $this->postJson(cpqUrl("/models/{$this->otherModelId}/confirm"), ['selections' => cpqPumpSelections()])->assertCreated()->assertJsonPath('meta.reused', false)->json('data');
    $original = cpqInTenant($this->tenant, fn () => CpqConfiguredInstance::query()->findOrFail($this->instanceId));

    expect($mine['id'])->not->toBe($this->instanceId)
        ->and($mine['configuration_hash'])->not->toBe($original->configuration_hash)
        ->and($mine['part_number'])->toBe($original->part_number);
});

it('scopes the part number sequence and the unique hash to the company and the model', function (): void {
    $this->postJson(cpqUrl("/models/{$this->modelId}/confirm"), ['selections' => cpqPumpSelections(['flow_rate' => 45])])->assertCreated()->assertJsonPath('data.part_number', fn (string $number) => str_ends_with($number, '-0002'));

    cpqActor($this, 'owner', $this->otherTenant);
    $this->postJson(cpqUrl("/models/{$this->otherModelId}/confirm"), ['selections' => cpqPumpSelections()])->assertCreated()->assertJsonPath('data.part_number', fn (string $number) => str_ends_with($number, '-0001'));

    expect(cpqInTenant($this->tenant, fn () => CpqConfiguredInstance::query()->count()))->toBe(2)
        ->and(cpqInTenant($this->otherTenant, fn () => CpqConfiguredInstance::query()->count()))->toBe(1);
});

it('stamps the active company on everything it writes and never lets a request choose it', function (): void {
    $id = $this->postJson(cpqUrl('/models'), ['code' => 'SPOOF', 'name' => ['it' => 'x'], 'part_number_prefix' => 'SP', 'tenant_id' => $this->otherTenant->id])->assertCreated()->json('data.id');

    $stored = CpqModel::query()->withoutGlobalScopes()->findOrFail($id);
    $characteristicId = $this->postJson(cpqUrl("/models/{$id}/characteristics"), ['code' => 'x', 'type' => 'boolean', 'label' => ['it' => 'x'], 'tenant_id' => $this->otherTenant->id])->assertCreated()->json('data.id');

    expect($stored->tenant_id)->toBe($this->tenant->id)
        ->and(CpqCharacteristic::query()->withoutGlobalScopes()->findOrFail($characteristicId)->tenant_id)->toBe($this->tenant->id);
});

it('seeds the demo models for every company, each with its own rows', function (): void {
    $rows = CpqModel::query()->withoutGlobalScopes()->whereIn('code', ['PUMP-SKID', 'ELEC-PANEL'])->get()->groupBy('tenant_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$this->tenant->id])->toHaveCount(2)
        ->and($rows[$this->otherTenant->id])->toHaveCount(2);
});

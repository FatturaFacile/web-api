<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\Cpq\ElectricalPanelModel;
use Database\Seeders\Cpq\PumpSkidModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqCharacteristicOption;
use Modules\CpqConfigurator\Models\CpqConstraintRule;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Models\CpqPricingFormula;
use Modules\CpqConfigurator\Models\CpqRoutingMapping;
use Modules\CpqConfigurator\Models\CpqSuperBomMapping;

/**
 * Mod 230 — demo models of the technical-commercial CPQ configurator for every company: an industrial pump skid and an
 * electrical distribution panel, with their characteristics, options, rules, super-BOM, routing and pricing formulas. The
 * texts of the configurator (labels, help, constraint explanations, proposal clauses) are written here in it, en, de, fr and
 * served by the API. Idempotent: records are matched by code, so a second run changes nothing and keeps the version and the
 * part-number sequence of a model that is already in use.
 */
class CpqConfiguratorDemoSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $context->runWithTenant($tenant, fn () => $this->seedTenant());
        }
    }

    /**
     * Seeds the models for the company of the active tenant context.
     */
    public function seedTenant(): void
    {
        foreach ([PumpSkidModel::definition(), ElectricalPanelModel::definition()] as $definition) {
            $this->seedModel($definition);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedModel(array $definition): void
    {
        $children = Arr::only($definition, ['characteristics', 'rules', 'bom', 'routing', 'pricing']);
        $header = Arr::except($definition, array_keys($children));
        $existing = CpqModel::query()->where('code', $header['code'])->first();

        $model = CpqModel::query()->updateOrCreate(
            ['code' => $header['code']],
            Arr::except($header, ['code']) + ['status' => CpqModel::Published, 'published_at' => $existing->published_at ?? now()],
        );

        foreach ($children['characteristics'] as $characteristic) {
            $record = CpqCharacteristic::query()->updateOrCreate(
                ['model_id' => $model->id, 'code' => $characteristic['code']],
                Arr::except($characteristic, ['code', 'options']) + ['is_required' => true],
            );

            foreach ($characteristic['options'] ?? [] as $index => $option) {
                CpqCharacteristicOption::query()->updateOrCreate(
                    ['characteristic_id' => $record->id, 'code' => $option['code']],
                    Arr::except($option, ['code']) + ['model_id' => $model->id, 'sort_order' => ($index + 1) * 10, 'is_default' => false],
                );
            }
        }

        $this->seedChildren($model, CpqConstraintRule::class, $children['rules']);
        $this->seedChildren($model, CpqSuperBomMapping::class, $children['bom']);
        $this->seedChildren($model, CpqRoutingMapping::class, $children['routing']);
        $this->seedChildren($model, CpqPricingFormula::class, $children['pricing']);
    }

    /**
     * @param  class-string<Model>  $class
     * @param  list<array<string, mixed>>  $rows
     */
    private function seedChildren(CpqModel $model, string $class, array $rows): void
    {
        foreach ($rows as $row) {
            $class::query()->updateOrCreate(['model_id' => $model->id, 'code' => $row['code']], Arr::except($row, ['code']));
        }
    }
}

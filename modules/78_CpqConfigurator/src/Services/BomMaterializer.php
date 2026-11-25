<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Services\ManufacturingMasterDataService;

/**
 * Turns a confirmed configuration into master data of the manufacturing module: a finished-good item carrying the smart
 * part number and a draft BOM with the resolved lines of the super-BOM (the component items are created on first use,
 * with the unit cost of the line as standard cost). The BOM stays a draft: engineering releases it as usual.
 */
final class BomMaterializer
{
    public function __construct(
        private readonly ManufacturingMasterDataService $items,
        private readonly BillOfMaterialsServiceInterface $boms,
    ) {}

    /**
     * @param  array<string, mixed>  $evaluation  result of ConfigurationEvaluator::evaluate
     * @return array{item: MfgItem, bom: MfgBom}
     */
    public function materialize(CpqConfiguredInstance $instance, array $evaluation, string $modelName): array
    {
        $tenantId = (string) $instance->tenant_id;

        $item = MfgItem::query()->where('item_code', $instance->part_number)->first()
            ?? $this->items->createItem($tenantId, [
                'item_code' => $instance->part_number,
                'name' => $modelName.' '.$instance->part_number,
                'description' => $instance->configuration_hash,
                'item_type' => 'finished_good',
                'uom' => 'PCS',
                'lead_time_days' => $instance->lead_time_days,
                'standard_cost' => $instance->industrial_cost,
            ]);

        $components = [];

        foreach ($evaluation['bom'] as $line) {
            $component = MfgItem::query()->where('item_code', $line['item_code'])->first()
                ?? $this->items->createItem($tenantId, [
                    'item_code' => $line['item_code'],
                    'name' => (string) ($line['name'] ?: $line['item_code']),
                    'item_type' => 'raw_material',
                    'uom' => (string) $line['uom'],
                    'standard_cost' => (string) $line['unit_cost'],
                    'lead_time_days' => (int) $line['lead_time_days'],
                ]);

            if ((string) $component->id === (string) $item->id) {
                continue;
            }

            $components[] = ['component_item_id' => (string) $component->id, 'quantity_per' => (string) $line['quantity'], 'notes' => (string) $line['code']];
        }

        $bom = MfgBom::query()->where('mfg_item_id', $item->id)->first()
            ?? $this->boms->createBom(new CreateBomDTO(
                tenantId: $tenantId,
                mfgItemId: (string) $item->id,
                name: $modelName.' '.$instance->part_number,
                components: $components,
                bomNumber: $instance->part_number,
                notes: 'CPQ '.$instance->configuration_hash,
            ));

        return ['item' => $item, 'bom' => $bom];
    }
}

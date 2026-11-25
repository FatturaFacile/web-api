<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\TechnicalCpqConfiguredEvent;
use Modules\SalesBoundedContext\Models\SbcCpqConfiguration;

final readonly class ConfigureTechnicalCpqBomHandler
{
    public function handle(ConfigureTechnicalCpqBomCommand $command): SbcCpqConfiguration
    {
        $data = $command->payload;
        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));

        /** @var array<string, mixed> $selectedOptions */
        $selectedOptions = is_array($data['selected_options'] ?? null)
            ? $data['selected_options']
            : ['voltage' => '400V', 'chiller' => 'high_capacity', 'plc' => 'siemens_s7'];

        $conflicts = [];
        if (($selectedOptions['voltage'] ?? null) === '230V' && ($selectedOptions['chiller'] ?? null) === 'high_capacity') {
            $conflicts[] = 'High-capacity chiller requires 400V 3-phase power supply.';
        }
        $hasConflict = $conflicts !== [];

        $rulesEvaluated = [
            ['rule' => 'RULE_VOLTAGE_CHILLER_COMPAT', 'passed' => ! $hasConflict],
            ['rule' => 'RULE_PLC_SAFETY_INTERLOCK', 'passed' => true],
        ];

        $bomItems = is_array($data['super_bom_items'] ?? null)
            ? $data['super_bom_items']
            : [
                ['sku' => 'CMP-CHASSIS-400', 'qty' => 1, 'unit_cost' => 4200.00, 'unit_price' => 6800.00],
                ['sku' => 'CMP-CHILLER-HC', 'qty' => 1, 'unit_cost' => 2800.00, 'unit_price' => 4500.00],
                ['sku' => 'CMP-PLC-S7', 'qty' => 1, 'unit_cost' => 1500.00, 'unit_price' => 2700.00],
            ];

        $totalCostFloat = 0.0;
        $totalPriceFloat = 0.0;
        foreach ($bomItems as $item) {
            $qty = max(1.0, (float) ($item['qty'] ?? 1));
            $totalCostFloat += $qty * (float) ($item['unit_cost'] ?? 0);
            $totalPriceFloat += $qty * (float) ($item['unit_price'] ?? 0);
        }

        $bomCost = DecimalMoney::of($totalCostFloat, $currency);
        $listPrice = DecimalMoney::of($totalPriceFloat, $currency);
        $discountPct = max(0.0, min(50.0, (float) ($data['discount_pct'] ?? 5.0)));
        $netPrice = $listPrice->applyCascadingDiscounts([$discountPct]);
        $marginPct = $netPrice->marginPercentage($bomCost);

        $config = DB::transaction(function () use (
            $command,
            $data,
            $selectedOptions,
            $rulesEvaluated,
            $hasConflict,
            $conflicts,
            $bomItems,
            $bomCost,
            $listPrice,
            $netPrice,
            $marginPct,
            $currency,
        ): SbcCpqConfiguration {
            return SbcCpqConfiguration::query()->create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'quote_id' => $data['quote_id'] ?? null,
                'configuration_code' => (string) ($data['configuration_code'] ?? 'CFG-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 6))),
                'product_family' => (string) ($data['product_family'] ?? 'Industrial CNC Cell'),
                'selected_options' => $selectedOptions,
                'compatibility_rules_evaluated' => $rulesEvaluated,
                'has_constraint_conflict' => $hasConflict,
                'conflict_messages' => $conflicts,
                'super_bom_items' => $bomItems,
                'routing_operations' => $data['routing_operations'] ?? [
                    ['op' => '10_ASSEMBLY', 'hours' => 12],
                    ['op' => '20_FAT_CALIBRATION', 'hours' => 4],
                ],
                'total_bom_cost' => $bomCost->roundHalfUp(),
                'configured_list_price' => $listPrice->roundHalfUp(),
                'net_configured_price' => $netPrice->roundHalfUp(),
                'calculated_margin_pct' => $marginPct,
                'currency' => $currency,
                'status' => $hasConflict ? 'conflict_blocked' : 'validated',
            ]);
        });

        TechnicalCpqConfiguredEvent::dispatch(
            $command->tenantId,
            (string) $config->id,
            $hasConflict,
            $marginPct,
        );

        return $config;
    }
}

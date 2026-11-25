<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Compliance;

use Modules\IndustrialLaundry\Domain\Enums\Jurisdiction;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Support\Money;

/**
 * Italian and Swiss billing of the laundry: the roll cages travel as returnable containers ("contenitori a rendere"), the month
 * is billed with one deferred invoice (TD24 in Italy, QR-bill in Switzerland) that quotes the delivery notes of the period, and
 * the industrial water discharge is reported per kg of linen treated.
 */
final class ChItLaundryBillingStrategy implements IndustrialLaundryComplianceStrategyInterface
{
    public function key(): string
    {
        return 'ch_it_laundry_billing';
    }

    public function evaluateGarment(Garment $garment, int $projectedWashCycles): array
    {
        return [];
    }

    public function evaluateWashBatch(WashBatchMeasurements $measurements): array
    {
        return [];
    }

    public function decorateBilling(BillingContext $context, BillingDecoration $decoration): BillingDecoration
    {
        $italian = $context->contract->jurisdiction === Jurisdiction::It;
        $litres = Money::weight(Money::mul($context->netWeightKg, (string) config('industrial_laundry.discharge.water_litres_per_kg', '8')));
        $notes = [];

        if ($context->deliveryNoteNumbers !== []) {
            $notes[] = ['key' => $italian ? 'ddt_references_it' : 'ddt_references_ch', 'params' => ['numbers' => implode(', ', $context->deliveryNoteNumbers)]];
        }

        $notes[] = ['key' => 'returnable_containers', 'params' => ['out' => $context->rollCages['out'], 'back' => $context->rollCages['back'], 'at_customer' => $context->rollCages['at_customer']]];

        return $decoration->with(
            documentType: $italian ? 'TD24' : null,
            paymentFlavour: $italian ? 'sdi_deferred' : 'qr_bill',
            notes: $notes,
            report: [
                'returnable_containers' => $context->rollCages,
                'water_discharge' => [
                    'treated_net_kg' => Money::weight($context->netWeightKg),
                    'litres_per_kg' => (string) config('industrial_laundry.discharge.water_litres_per_kg', '8'),
                    'discharged_litres' => $litres,
                    'discharged_m3' => Money::weight(Money::mul($litres, '0.001')),
                ],
                'delivery_notes' => $context->deliveryNoteNumbers,
                'document' => $italian ? 'TD24' : 'QR-Bill',
            ],
        );
    }
}

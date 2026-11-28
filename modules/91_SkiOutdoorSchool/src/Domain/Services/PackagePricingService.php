<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Services;

use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemType;
use Modules\SkiOutdoorSchool\Domain\Enums\VatRegime;

/**
 * Invoice lines of a bundled package (lesson or course + rental + ARTVA kit + skipass). The instruction is exempt from VAT (Italy: Art. 10 n. 20
 * DPR 633/72, nature N4; Switzerland: Art. 21 para. 2 no. 11 LIVA) while rentals and skipasses are taxable at the rate of the jurisdiction.
 * Pure: the net of each line is computed in cents.
 *
 * @phpstan-type Line array{description: string, quantity: float, unit_price: float, tax_rate: float, nature: string|null, legal_reference: string|null, kind: string}
 * @phpstan-type Item array{item_type: string, description: string|null, quantity: float, days: int, unit_price_net: float, vat_rate: float}
 */
final class PackagePricingService
{
    /**
     * @param  list<Item>  $items
     * @return list<Line>
     */
    public function lines(string $instructionDescription, float $instructionNet, VatRegime $regime, array $items, string $itemDescriptionPrefix = ''): array
    {
        $lines = [[
            'description' => $instructionDescription.($regime->legalReference() !== null ? ' — '.$regime->legalReference() : ''),
            'quantity' => 1.0,
            'unit_price' => round($instructionNet, 2),
            'tax_rate' => $regime->isExempt() ? 0.0 : $this->defaultRate($regime->jurisdiction(), 'other'),
            'nature' => $regime->sdiNature(),
            'legal_reference' => $regime->legalReference(),
            'kind' => 'instruction',
        ]];

        foreach ($items as $item) {
            $lines[] = [
                'description' => trim($itemDescriptionPrefix.' '.($item['description'] ?? $item['item_type'])),
                'quantity' => round($item['quantity'] * max(1, $item['days']), 2),
                'unit_price' => round($item['unit_price_net'], 2),
                'tax_rate' => round($item['vat_rate'], 2),
                'nature' => null,
                'legal_reference' => null,
                'kind' => $item['item_type'],
            ];
        }

        return $lines;
    }

    /**
     * The VAT rate the school applies by default to an add-on of the given type.
     */
    public function addOnRate(string $jurisdiction, PackageItemType $type): float
    {
        return $this->defaultRate($jurisdiction, $type->vatCategory());
    }

    /**
     * @param  list<Line>  $lines
     * @return array{net_cents: int, vat_cents: int, gross_cents: int}
     */
    public function totals(array $lines): array
    {
        $net = 0;
        $vat = 0;

        foreach ($lines as $line) {
            $lineNet = (int) round($line['quantity'] * $line['unit_price'] * 100);
            $net += $lineNet;
            $vat += (int) round($lineNet * $line['tax_rate'] / 100);
        }

        return ['net_cents' => $net, 'vat_cents' => $vat, 'gross_cents' => $net + $vat];
    }

    private function defaultRate(string $jurisdiction, string $category): float
    {
        return (float) config('ski_school.vat_rates.'.strtoupper($jurisdiction).'.'.$category, 0.0);
    }
}

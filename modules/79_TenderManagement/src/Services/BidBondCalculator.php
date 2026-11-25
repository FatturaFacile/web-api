<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Contracts\TenderProcurementComplianceStrategyInterface;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderNotice;

/**
 * The provisional bid bond (garanzia provvisoria): 1 % or 2 % of the base auction amount, cut by the cumulative legal
 * reductions the company is entitled to through its valid vault certifications (Art. 106 D.Lgs. 36/2023). The reductions
 * multiply; ISO 14001 and EMAS are alternatives and only the larger applies.
 */
final class BidBondCalculator
{
    public function __construct(private readonly VaultStatusResolver $status) {}

    /**
     * @param  Collection<int, QualificationVaultDocument>  $vault
     * @return array{base_percentage: string, base_amount: string, reductions: list<array{key: string, doc_type: string, percentage: string, document_id: string}>, final_amount: string, total_reduction_percentage: string}
     */
    public function calculate(TenderNotice $notice, TenderProcurementComplianceStrategyInterface $strategy, Collection $vault, string $basePercentage, CarbonImmutable $today): array
    {
        $allowed = array_map(static fn (string $value): string => Dec::percent($value), (array) config('tender_management.bid_bond.allowed_base_percentages', ['1.00', '2.00']));

        if (! in_array(Dec::percent($basePercentage), $allowed, true)) {
            throw new TenderException('bid_bond_percentage_invalid');
        }

        $baseAmount = Dec::money(Dec::div(Dec::mul($notice->base_auction_amount, $basePercentage), 100));
        $rates = (array) config('tender_management.bid_bond.reductions', []);
        $applied = [];

        foreach ($vault as $document) {
            $key = $document->doc_type->bondReductionKey();

            if ($key === null || ! in_array($key, $strategy->bidBondReductionKeys(), true) || ! isset($rates[$key]) || ! $this->status->isUsable($document, $today)) {
                continue;
            }

            $applied[$key] ??= ['key' => $key, 'doc_type' => $document->doc_type->value, 'percentage' => Dec::percent(Dec::mul($rates[$key], 100)), 'document_id' => (string) $document->id];
        }

        if (isset($applied['emas'], $applied['iso14001'])) {
            unset($applied['iso14001']);
        }

        $factor = Dec::of(1);
        foreach ($applied as $reduction) {
            $factor = Dec::mul($factor, Dec::sub(1, Dec::div($reduction['percentage'], 100)));
        }

        return [
            'base_percentage' => Dec::money($basePercentage),
            'base_amount' => $baseAmount,
            'reductions' => array_values($applied),
            'final_amount' => Dec::money(Dec::mul($baseAmount, $factor)),
            'total_reduction_percentage' => Dec::percent(Dec::mul(Dec::sub(1, $factor), 100)),
        ];
    }
}

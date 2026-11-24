<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use Carbon\CarbonImmutable;
use Modules\SwissVat\Domain\Enums\SwissVatExemptionType;
use Modules\SwissVat\Domain\Enums\SwissVatMethod;

/**
 * Pure deterministic Swiss VAT calculation engine supporting both:
 * - Effective Method (Form 050): quarterly/monthly/semi-annual with full input tax deduction & adjustments
 * - Saldosteuersatz Method (Form 051): semi-annual/configured interval on gross turnover without input VAT deduction.
 * Supports 2024+ rates (8.1%, 2.6%, 3.8%) and historical 2018-2023 rates (7.7%, 2.5%, 3.7%).
 */
final class SwissVatCalculatorService
{
    public function __construct(
        private readonly SwissVatRateCatalogService $catalog,
    ) {}

    /**
     * @param  array{
     *     period_from: string,
     *     period_to: string,
     *     accounting_method: string,
     *     rounding_mode?: string,
     *     sss_rate_primary?: string|null,
     *     sss_rate_secondary?: string|null,
     *     sales_lines?: list<array{
     *         net_amount: string|int|float,
     *         tax_amount?: string|int|float|null,
     *         tax_rate?: string|int|float,
     *         exemption_type?: string|null,
     *         afc_box?: string|null,
     *         sss_rate?: string|int|float|null,
     *         is_credit_note?: bool,
     *     }>,
     *     purchase_lines?: list<array{
     *         net_amount?: string|int|float,
     *         input_vat_amount: string|int|float,
     *         afc_box?: string|null,
     *         is_deductible?: bool,
     *     }>,
     *     bezugsteuer_entries?: list<array{net_amount_chf: string|int|float, tax_amount_chf: string|int|float, vat_rate_percent?: string|int|float, deductible_input_tax_chf?: string|int|float}>,
     *     adjustments?: array{box_410?: string|int|float, box_415?: string|int|float, box_420?: string|int|float},
     *     other_flows?: array{box_900?: string|int|float, box_910?: string|int|float},
     *     reductions_box_235?: string|int|float,
     * }  $input
     * @return array{
     *     afc_boxes: array<int|string, mixed>,
     *     box_200: string,
     *     box_289: string,
     *     box_299: string,
     *     box_399: string,
     *     box_479: string,
     *     box_500: string,
     *     box_510: string,
     *     gross_turnover: string,
     *     invoiced_vat_total: string,
     *     forfeited_input_vat: string,
     *     sss_revenue_adjustment: string,
     *     bezugsteuer_base: string,
     *     bezugsteuer_tax: string,
     *     adjustments_box_410: string,
     *     adjustments_box_415: string,
     *     adjustments_box_420: string,
     * }
     */
    public function calculate(array $input): array
    {
        $periodTo = CarbonImmutable::parse((string) $input['period_to']);
        $epoch = $this->catalog->ratesForDate($periodTo);
        $method = SwissVatMethod::from((string) $input['accounting_method']);
        $isSss = $method === SwissVatMethod::Saldosteuersatz;
        $roundingMode = (string) ($input['rounding_mode'] ?? 'chf_005');
        $sssPrimary = (string) ($input['sss_rate_primary'] ?? '6.20');
        $sssSecondary = $input['sss_rate_secondary'] !== null && $input['sss_rate_secondary'] !== ''
            ? (string) $input['sss_rate_secondary']
            : null;

        $box205 = '0';
        $box220 = '0';
        $box221 = '0';
        $box225 = '0';
        $box230 = '0';
        $box235 = (string) ($input['reductions_box_235'] ?? '0');
        $box280 = '0';
        $box900 = (string) ($input['other_flows']['box_900'] ?? '0');
        $box910 = (string) ($input['other_flows']['box_910'] ?? '0');

        $netTurnover = '0';
        $invoicedVatTotal = '0';

        // Effective buckets (2024+ 303/313/343 and 2018-2023 302/312/342)
        $stdBox = $epoch['boxes']['standard'];
        $redBox = $epoch['boxes']['reduced'];
        $accBox = $epoch['boxes']['accommodation'];
        $bezBox = $epoch['boxes']['bezugsteuer'];
        $sss1Box = $epoch['boxes']['sss1'];
        $sss2Box = $epoch['boxes']['sss2'];

        $rateBuckets = [
            $stdBox => ['base' => '0', 'rate' => $epoch['standard'], 'tax' => '0', 'alias' => $stdBox === '303' ? '302' : '303'],
            $redBox => ['base' => '0', 'rate' => $epoch['reduced'], 'tax' => '0', 'alias' => $redBox === '313' ? '312' : '313'],
            $accBox => ['base' => '0', 'rate' => $epoch['accommodation'], 'tax' => '0', 'alias' => $accBox === '343' ? '342' : '343'],
        ];

        $sssBuckets = [];

        foreach ($input['sales_lines'] ?? [] as $line) {
            $sign = ! empty($line['is_credit_note']) ? '-1' : '1';
            $net = SwissVatDecimal::mul($sign, $line['net_amount']);
            $rateNum = (string) ($line['tax_rate'] ?? '0');
            $exemption = (string) ($line['exemption_type'] ?? SwissVatExemptionType::None->value);
            $requestedBox = isset($line['afc_box']) ? (string) $line['afc_box'] : null;

            $tax = isset($line['tax_amount'])
                ? SwissVatDecimal::mul($sign, $line['tax_amount'])
                : SwissVatDecimal::round2(SwissVatDecimal::div(SwissVatDecimal::mul($net, $rateNum), '100'));

            // Out-of-scope flows go to Box 900/910, excluded from Box 200
            if ($exemption === SwissVatExemptionType::OutOfScope->value || in_array($requestedBox, ['900', '910'], true)) {
                if ($requestedBox === '910') {
                    $box910 = SwissVatDecimal::add($box910, $net);
                } else {
                    $box900 = SwissVatDecimal::add($box900, $net);
                }

                continue;
            }

            $netTurnover = SwissVatDecimal::add($netTurnover, $net);

            // Exempt or zero-rated supplies
            if (SwissVatDecimal::compare($rateNum, '0') <= 0 && SwissVatDecimal::isZero($tax)) {
                $deductionBox = match (true) {
                    in_array($requestedBox, ['220', '221', '225', '230', '235', '280'], true) => $requestedBox,
                    $exemption === SwissVatExemptionType::ExemptWithoutDeduction->value => '230',
                    default => '220',
                };

                match ($deductionBox) {
                    '220' => $box220 = SwissVatDecimal::add($box220, $net),
                    '221' => $box221 = SwissVatDecimal::add($box221, $net),
                    '225' => $box225 = SwissVatDecimal::add($box225, $net),
                    '230' => $box230 = SwissVatDecimal::add($box230, $net),
                    '235' => $box235 = SwissVatDecimal::add($box235, $net),
                    '280' => $box280 = SwissVatDecimal::add($box280, $net),
                };

                continue;
            }

            if ($requestedBox === '205') {
                $box205 = SwissVatDecimal::add($box205, $net);
            }

            $invoicedVatTotal = SwissVatDecimal::add($invoicedVatTotal, $tax);

            if ($isSss) {
                $grossLine = SwissVatDecimal::add($net, $tax);
                $sssRate = isset($line['sss_rate']) && SwissVatDecimal::isPositive($line['sss_rate'])
                    ? SwissVatDecimal::round2($line['sss_rate'])
                    : SwissVatDecimal::round2($sssPrimary);

                if (! isset($sssBuckets[$sssRate])) {
                    $sssBuckets[$sssRate] = ['base' => '0', 'rate' => $sssRate, 'tax' => '0'];
                }

                $sssBuckets[$sssRate]['base'] = SwissVatDecimal::add($sssBuckets[$sssRate]['base'], $grossLine);
                $lineSssTax = SwissVatDecimal::div(SwissVatDecimal::mul($grossLine, $sssRate), '100');
                $sssBuckets[$sssRate]['tax'] = SwissVatDecimal::add($sssBuckets[$sssRate]['tax'], $lineSssTax);
            } else {
                $bucketCode = $this->resolveEffectiveRateBox($rateNum, $requestedBox, $epoch['boxes']);
                $rateBuckets[$bucketCode]['base'] = SwissVatDecimal::add($rateBuckets[$bucketCode]['base'], $net);
                $rateBuckets[$bucketCode]['tax'] = SwissVatDecimal::add($rateBuckets[$bucketCode]['tax'], $tax);
            }
        }

        // Deductions & Taxable Turnover (200 - 289 = 299)
        $box289 = SwissVatDecimal::add(
            SwissVatDecimal::add(SwissVatDecimal::add($box220, $box221), SwissVatDecimal::add($box225, $box230)),
            SwissVatDecimal::add($box235, $box280),
        );

        $grossTurnover = SwissVatDecimal::add($netTurnover, $invoicedVatTotal);
        $box200 = $isSss ? $grossTurnover : $netTurnover;
        $box299 = SwissVatDecimal::sub($box200, $box289);

        // Output tax calculation
        $salesOutputTax = '0';

        if ($isSss) {
            $idx = 0;
            // Ensure primary rate appears first, secondary second
            uksort($sssBuckets, static function (string $a, string $b) use ($sssPrimary): int {
                if (SwissVatDecimal::compare($a, $sssPrimary) === 0) {
                    return -1;
                }
                if (SwissVatDecimal::compare($b, $sssPrimary) === 0) {
                    return 1;
                }

                return SwissVatDecimal::compare($b, $a);
            });

            $sssNormalized = [];
            foreach ($sssBuckets as $rate => $bucket) {
                $boxCode = $idx === 0 ? $sss1Box : $sss2Box;
                $taxRounded = SwissVatDecimal::round2($bucket['tax']);
                $salesOutputTax = SwissVatDecimal::add($salesOutputTax, $taxRounded);
                $sssNormalized[$boxCode] = [
                    'base' => SwissVatDecimal::round2($bucket['base']),
                    'rate' => $rate,
                    'tax' => $taxRounded,
                    'alias' => $boxCode === '323' ? '322' : ($boxCode === '333' ? '332' : null),
                ];
                $idx++;
            }

            if (! isset($sssNormalized[$sss1Box])) {
                $sssNormalized[$sss1Box] = ['base' => '0.00', 'rate' => SwissVatDecimal::round2($sssPrimary), 'tax' => '0.00', 'alias' => $sss1Box === '323' ? '322' : '323'];
            }
            if ($sssSecondary !== null && ! isset($sssNormalized[$sss2Box])) {
                $sssNormalized[$sss2Box] = ['base' => '0.00', 'rate' => SwissVatDecimal::round2($sssSecondary), 'tax' => '0.00', 'alias' => $sss2Box === '333' ? '332' : '333'];
            }
        } else {
            foreach ($rateBuckets as $code => $bucket) {
                $rateBuckets[$code]['base'] = SwissVatDecimal::round2($bucket['base']);
                $rateBuckets[$code]['tax'] = SwissVatDecimal::round2($bucket['tax']);
                $salesOutputTax = SwissVatDecimal::add($salesOutputTax, $rateBuckets[$code]['tax']);
            }
        }

        // Bezugsteuer (Box 382 / 383)
        $bezBase = '0';
        $bezTax = '0';
        $bezDeductibleInput = '0';

        foreach ($input['bezugsteuer_entries'] ?? [] as $bez) {
            $bezBase = SwissVatDecimal::add($bezBase, $bez['net_amount_chf']);
            $bezTax = SwissVatDecimal::add($bezTax, $bez['tax_amount_chf']);
            if (! $isSss) {
                $ded = $bez['deductible_input_tax_chf'] ?? $bez['tax_amount_chf'];
                $bezDeductibleInput = SwissVatDecimal::add($bezDeductibleInput, $ded);
            }
        }

        $box399 = SwissVatDecimal::add($salesOutputTax, $bezTax);

        // Input tax (Boxes 400..420 -> 479)
        $box400 = '0';
        $box405 = '0';
        $rawForfeitedInput = '0';

        foreach ($input['purchase_lines'] ?? [] as $p) {
            $vat = (string) $p['input_vat_amount'];
            if (array_key_exists('is_deductible', $p) && ! $p['is_deductible']) {
                continue;
            }

            if ($isSss) {
                $rawForfeitedInput = SwissVatDecimal::add($rawForfeitedInput, $vat);

                continue;
            }

            if (($p['afc_box'] ?? '400') === '405') {
                $box405 = SwissVatDecimal::add($box405, $vat);
            } else {
                $box400 = SwissVatDecimal::add($box400, $vat);
            }
        }

        // Include Bezugsteuer self-assessed input tax in Box 400/405 for Effective method
        if (! $isSss && SwissVatDecimal::isPositive($bezDeductibleInput)) {
            $box400 = SwissVatDecimal::add($box400, $bezDeductibleInput);
        }

        $box410 = $isSss ? '0.00' : SwissVatDecimal::round2($input['adjustments']['box_410'] ?? '0');
        $box415 = $isSss ? '0.00' : SwissVatDecimal::round2($input['adjustments']['box_415'] ?? '0');
        $box420 = $isSss ? '0.00' : SwissVatDecimal::round2($input['adjustments']['box_420'] ?? '0');

        // 479 = 400 + 405 + 410 - 415 - 420
        $box479 = $isSss
            ? '0.00'
            : SwissVatDecimal::sub(
                SwissVatDecimal::add(SwissVatDecimal::add($box400, $box405), $box410),
                SwissVatDecimal::add($box415, $box420),
            );

        $rawBalance = SwissVatDecimal::sub($box399, $box479);
        $roundedBalance = SwissVatDecimal::roundByMode($rawBalance, $roundingMode);

        $box500 = SwissVatDecimal::isPositive($roundedBalance) ? $roundedBalance : '0.00';
        $box510 = SwissVatDecimal::isNegative($roundedBalance) ? SwissVatDecimal::round2(SwissVatDecimal::neg($roundedBalance)) : '0.00';

        // SSS gross-to-net revenue adjustment: invoiced VAT minus SSS tax on sales
        $sssRevenueAdjustment = $isSss
            ? SwissVatDecimal::round2(SwissVatDecimal::sub($invoicedVatTotal, $salesOutputTax))
            : '0.00';

        $afcBoxes = [
            '200' => SwissVatDecimal::round2($box200),
            '205' => SwissVatDecimal::round2($box205),
            '220' => SwissVatDecimal::round2($box220),
            '221' => SwissVatDecimal::round2($box221),
            '225' => SwissVatDecimal::round2($box225),
            '230' => SwissVatDecimal::round2($box230),
            '235' => SwissVatDecimal::round2($box235),
            '280' => SwissVatDecimal::round2($box280),
            '289' => SwissVatDecimal::round2($box289),
            '299' => SwissVatDecimal::round2($box299),
            $stdBox => $isSss ? ['base' => '0.00', 'rate' => $epoch['standard'], 'tax' => '0.00', 'alias' => $stdBox === '303' ? '302' : '303'] : $rateBuckets[$stdBox],
            $redBox => $isSss ? ['base' => '0.00', 'rate' => $epoch['reduced'], 'tax' => '0.00', 'alias' => $redBox === '313' ? '312' : '313'] : $rateBuckets[$redBox],
            $accBox => $isSss ? ['base' => '0.00', 'rate' => $epoch['accommodation'], 'tax' => '0.00', 'alias' => $accBox === '343' ? '342' : '343'] : $rateBuckets[$accBox],
            $bezBox => [
                'base' => SwissVatDecimal::round2($bezBase),
                'rate' => $epoch['standard'],
                'tax' => SwissVatDecimal::round2($bezTax),
                'alias' => $bezBox === '383' ? '382' : '383',
            ],
            '399' => SwissVatDecimal::round2($box399),
            '400' => SwissVatDecimal::round2($box400),
            '405' => SwissVatDecimal::round2($box405),
            '410' => SwissVatDecimal::round2($box410),
            '415' => SwissVatDecimal::round2($box415),
            '420' => SwissVatDecimal::round2($box420),
            '479' => SwissVatDecimal::round2($box479),
            '500' => $box500,
            '510' => $box510,
            '900' => SwissVatDecimal::round2($box900),
            '910' => SwissVatDecimal::round2($box910),
            'sss_rates' => $isSss ? array_values($sssNormalized) : [],
            'epoch' => $periodTo->toDateString() >= SwissVatRateCatalogService::CURRENT_EPOCH_START ? '2024+' : '2018-2023',
        ];

        return [
            'afc_boxes' => $afcBoxes,
            'box_200' => SwissVatDecimal::round2($box200),
            'box_289' => SwissVatDecimal::round2($box289),
            'box_299' => SwissVatDecimal::round2($box299),
            'box_399' => SwissVatDecimal::round2($box399),
            'box_479' => SwissVatDecimal::round2($box479),
            'box_500' => $box500,
            'box_510' => $box510,
            'gross_turnover' => SwissVatDecimal::round2($grossTurnover),
            'invoiced_vat_total' => SwissVatDecimal::round2($invoicedVatTotal),
            'forfeited_input_vat' => SwissVatDecimal::round2($rawForfeitedInput),
            'sss_revenue_adjustment' => $sssRevenueAdjustment,
            'bezugsteuer_base' => SwissVatDecimal::round2($bezBase),
            'bezugsteuer_tax' => SwissVatDecimal::round2($bezTax),
            'adjustments_box_410' => SwissVatDecimal::round2($box410),
            'adjustments_box_415' => SwissVatDecimal::round2($box415),
            'adjustments_box_420' => SwissVatDecimal::round2($box420),
        ];
    }

    /**
     * @param  array{standard: string, reduced: string, accommodation: string, bezugsteuer: string, sss1: string, sss2: string}  $epochBoxes
     */
    private function resolveEffectiveRateBox(string $rate, ?string $requestedBox, array $epochBoxes): string
    {
        if (in_array($requestedBox, [$epochBoxes['standard'], $epochBoxes['reduced'], $epochBoxes['accommodation']], true)) {
            return (string) $requestedBox;
        }

        if (SwissVatDecimal::compare($rate, '2.60') <= 0 && SwissVatDecimal::compare($rate, '2.40') >= 0) {
            return $epochBoxes['reduced'];
        }

        if (SwissVatDecimal::compare($rate, '3.90') <= 0 && SwissVatDecimal::compare($rate, '3.60') >= 0) {
            return $epochBoxes['accommodation'];
        }

        return $epochBoxes['standard'];
    }
}

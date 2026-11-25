<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Services;

use App\Models\Product;
use Modules\VanSalesAndFieldForce\Domain\ValueObjects\Gs1VariableWeightParseResult;

/**
 * Parses GS1-128 variable-weight barcodes (AI 01, 310x/320x, 392x, 10, 17)
 * as well as Italian/Swiss 13-digit scale EAN-13 barcodes (prefix 20..29).
 */
final class Gs1VariableWeightBarcodeService
{
    public function parse(string $tenantId, string $barcode): Gs1VariableWeightParseResult
    {
        $normalized = trim($barcode);
        $gtin = null;
        $skuCandidate = null;
        $netWeightKg = null;
        $variablePrice = null;
        $lotNumber = null;
        $expiryDate = null;
        $format = 'RAW';

        // 1. Human-readable GS1-128 with parenthesized AIs: (01)...(3103)...(10)...(17)...
        if (str_contains($normalized, '(01)') || str_contains($normalized, '(310') || str_contains($normalized, '(10)')) {
            $format = 'GS1-128';
            if (preg_match('/\(01\)(\d{14})/', $normalized, $m) === 1) {
                $gtin = $m[1];
                $skuCandidate = ltrim(substr($gtin, 1, 12), '0');
            }
            if (preg_match('/\(310(\d)\)(\d{6})/', $normalized, $m) === 1) {
                $decimals = (int) $m[1];
                $netWeightKg = round(((int) $m[2]) / (10 ** $decimals), 4);
            } elseif (preg_match('/\(320(\d)\)(\d{6})/', $normalized, $m) === 1) {
                $decimals = (int) $m[1];
                $pounds = ((int) $m[2]) / (10 ** $decimals);
                $netWeightKg = round($pounds * 0.45359237, 4);
            }
            if (preg_match('/\(392(\d)\)(\d{1,15})/', $normalized, $m) === 1) {
                $decimals = (int) $m[1];
                $variablePrice = round(((int) $m[2]) / (10 ** $decimals), 2);
            }
            if (preg_match('/\(10\)([^\(]{1,20})/', $normalized, $m) === 1) {
                $lotNumber = trim($m[1]);
            }
            if (preg_match('/\(17\)(\d{6})/', $normalized, $m) === 1) {
                $expiryDate = $this->parseYyMmDd($m[1]);
            }
        } elseif (preg_match('/^01(\d{14})310(\d)(\d{6})(?:10([^\x1d]{1,20}))?(?:\x1d?17(\d{6}))?/', $normalized, $m) === 1) {
            // 2. Raw FNC1 / unparenthesized GS1-128 stream
            $format = 'GS1-128-RAW';
            $gtin = $m[1];
            $skuCandidate = ltrim(substr($gtin, 1, 12), '0');
            $netWeightKg = round(((int) $m[3]) / (10 ** (int) $m[2]), 4);
            if (! empty($m[4])) {
                $lotNumber = trim($m[4]);
            }
            if (! empty($m[5])) {
                $expiryDate = $this->parseYyMmDd($m[5]);
            }
        } elseif (preg_match('/^(2\d)(\d{5})(\d{5})\d$/', $normalized, $m) === 1) {
            // 3. EAN-13 variable-weight scale barcode (2x PPPPP WWWWW C) -> weight in grams (5 digits / 1000 = kg)
            $format = 'EAN13-VAR-WEIGHT';
            $skuCandidate = $m[2];
            $netWeightKg = round(((int) $m[3]) / 1000, 4);
        }

        $product = $this->matchProduct($tenantId, $normalized, $gtin, $skuCandidate);
        $unitPrice = $product !== null ? (float) $product->sale_price : null;

        $computedTotal = null;
        if ($variablePrice !== null) {
            $computedTotal = $variablePrice;
        } elseif ($unitPrice !== null && $netWeightKg !== null) {
            $computedTotal = round($unitPrice * $netWeightKg, 2);
        }

        return new Gs1VariableWeightParseResult(
            rawBarcode: $normalized,
            format: $format,
            gtin: $gtin,
            skuCandidate: $skuCandidate,
            netWeightKg: $netWeightKg,
            variablePriceAmount: $variablePrice,
            lotNumber: $lotNumber,
            expiryDate: $expiryDate,
            matchedProductId: $product?->id !== null ? (string) $product->id : null,
            matchedProductSku: $product?->sku,
            matchedProductName: $product?->name,
            unitPrice: $unitPrice,
            computedLineTotal: $computedTotal,
        );
    }

    private function parseYyMmDd(string $yymmdd): ?string
    {
        if (strlen($yymmdd) !== 6) {
            return null;
        }
        $year = '20'.substr($yymmdd, 0, 2);
        $month = substr($yymmdd, 2, 2);
        $day = substr($yymmdd, 4, 2);
        if ($day === '00') {
            $day = '28';
        }

        return checkdate((int) $month, (int) $day, (int) $year)
            ? sprintf('%s-%s-%s', $year, $month, $day)
            : null;
    }

    private function matchProduct(string $tenantId, string $raw, ?string $gtin, ?string $skuCandidate): ?Product
    {
        $query = Product::query()->where('tenant_id', $tenantId);

        if ($gtin !== null) {
            $byGtin = (clone $query)
                ->where(function ($q) use ($gtin, $skuCandidate): void {
                    $q->where('barcode', $gtin);
                    if ($skuCandidate !== null && $skuCandidate !== '') {
                        $q->orWhere('barcode', $skuCandidate)
                            ->orWhere('sku', $skuCandidate);
                    }
                })
                ->first();
            if ($byGtin !== null) {
                return $byGtin;
            }
        }

        if ($skuCandidate !== null && $skuCandidate !== '') {
            $bySku = (clone $query)
                ->where(function ($q) use ($skuCandidate): void {
                    $q->where('barcode', 'like', '%'.$skuCandidate.'%')
                        ->orWhere('sku', 'like', '%'.$skuCandidate.'%');
                })
                ->first();
            if ($bySku !== null) {
                return $bySku;
            }
        }

        return (clone $query)->where('barcode', $raw)->orWhere('sku', $raw)->first();
    }
}

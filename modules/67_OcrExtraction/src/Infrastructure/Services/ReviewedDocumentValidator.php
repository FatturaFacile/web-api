<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionException;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;

/**
 * Business rules a reviewed document must satisfy before it becomes a business document: the master data it names exists
 * for the tenant, the fiscal period is open, the VAT codes belong to the tenant's jurisdiction (CH or IT), the totals
 * reconcile (CHF 0.05 / EUR 0.01) and the three-way match is within tolerance (or explicitly overridden with a reason).
 */
final readonly class ReviewedDocumentValidator
{
    public function __construct(private ThreeWayMatchDiscovery $matching) {}

    /**
     * @param  array<string, mixed>  $reviewed
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>, totals: array{net: float, vat: float, gross: float}, vendor_id: string|null, purchase_order_ids: list<string>, match: list<array<string, mixed>>}
     *
     * @throws OcrExtractionException when a rule is violated
     */
    public function validate(string $tenantId, string $tenantCountry, DocumentCategory $category, array $reviewed, ?string $defaultVendorId): array
    {
        $header = (array) ($reviewed['header'] ?? []);
        $rawLines = array_values((array) ($reviewed['lines'] ?? []));

        if ($rawLines === []) {
            throw new OcrExtractionException('The document has no lines.');
        }

        $vendorId = isset($reviewed['vendor_id']) && is_string($reviewed['vendor_id']) ? $reviewed['vendor_id'] : $defaultVendorId;

        if ($category !== DocumentCategory::ExpenseReceipt && $vendorId === null) {
            throw new OcrExtractionException('Choose the vendor of the document.');
        }

        if ($vendorId !== null && ! DB::table('contacts')->where('tenant_id', $tenantId)->where('id', $vendorId)->whereNull('deleted_at')->exists()) {
            throw new OcrExtractionException('The vendor does not exist.');
        }

        $date = ExtractedPayloadDto::toDate(isset($header['issue_date']) ? (string) $header['issue_date'] : null);

        if ($date === null) {
            throw new OcrExtractionException('The issue date of the document is missing or invalid.');
        }

        $this->assertPeriodOpen($tenantId, $date);

        $needsCoding = $category === DocumentCategory::VendorInvoice || $category === DocumentCategory::ExpenseReceipt;
        $lines = [];

        foreach ($rawLines as $index => $line) {
            $lines[] = $this->line($tenantId, $tenantCountry, $needsCoding, (array) $line, $index + 1);
        }

        $currency = strtoupper((string) ($header['currency'] ?? 'EUR'));
        $net = round(array_sum(array_column($lines, 'net_amount')), 2);
        $totals = (array) ($reviewed['totals'] ?? []);
        $gross = ExtractedPayloadDto::toFloat($totals['gross'] ?? null);
        $vat = ExtractedPayloadDto::toFloat($totals['vat'] ?? null) ?? round(array_sum(array_map(static fn (array $line): float => $line['net_amount'] * $line['vat_rate'] / 100, $lines)), 2);
        $gross ??= round($net + $vat, 2);
        $tolerance = $currency === 'CHF' ? 0.05 : 0.01;

        if (abs($net + $vat - $gross) > $tolerance + 1e-9) {
            throw new OcrExtractionException(sprintf('The totals do not reconcile: lines %.2f + VAT %.2f differ from the gross total %.2f by more than %.2f %s.', $net, $vat, $gross, $tolerance, $currency));
        }

        $orderIds = array_values(array_unique(array_filter((array) ($reviewed['purchase_order_ids'] ?? []), 'is_string')));
        $match = $category === DocumentCategory::VendorInvoice ? $this->assertMatch($tenantId, $vendorId, $orderIds, $lines, $reviewed) : [];

        return [
            'header' => [...$header, 'issue_date' => $date, 'due_date' => ExtractedPayloadDto::toDate(isset($header['due_date']) ? (string) $header['due_date'] : null), 'currency' => $currency],
            'lines' => $lines,
            'totals' => ['net' => $net, 'vat' => $vat, 'gross' => $gross],
            'vendor_id' => $vendorId,
            'purchase_order_ids' => $orderIds,
            'match' => $match,
        ];
    }

    private function assertPeriodOpen(string $tenantId, string $date): void
    {
        $period = DB::table('fiscal_periods')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        if ($period !== null && $period->status !== 'open') {
            throw new OcrExtractionException("The fiscal period {$period->period_name} of {$date} is {$period->status}.");
        }
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function line(string $tenantId, string $tenantCountry, bool $needsCoding, array $line, int $number): array
    {
        $description = trim((string) ($line['description'] ?? ''));

        if ($description === '') {
            throw new OcrExtractionException("Line {$number} has no description.");
        }

        $quantity = ExtractedPayloadDto::toFloat($line['quantity'] ?? null) ?? 1.0;
        $unitPrice = ExtractedPayloadDto::toFloat($line['unit_price'] ?? null) ?? 0.0;
        $netAmount = ExtractedPayloadDto::toFloat($line['net_amount'] ?? null) ?? round($quantity * $unitPrice, 2);
        $glId = isset($line['gl_account_id']) && is_string($line['gl_account_id']) && $line['gl_account_id'] !== '' ? $line['gl_account_id'] : null;
        $vatId = isset($line['vat_code_id']) && is_string($line['vat_code_id']) && $line['vat_code_id'] !== '' ? $line['vat_code_id'] : null;
        $costCenterId = isset($line['cost_center_id']) && is_string($line['cost_center_id']) && $line['cost_center_id'] !== '' ? $line['cost_center_id'] : null;

        if ($needsCoding && ($glId === null || $vatId === null)) {
            throw new OcrExtractionException("Line {$number} needs a GL account and a VAT code.");
        }

        $vatRate = ExtractedPayloadDto::toFloat($line['vat_rate'] ?? null);
        $vatNature = null;

        if ($glId !== null && ! $this->visible('chart_of_accounts', $tenantId, $glId)) {
            throw new OcrExtractionException("Line {$number}: the GL account does not exist.");
        }

        if ($costCenterId !== null && ! $this->visible('cost_centers', $tenantId, $costCenterId)) {
            throw new OcrExtractionException("Line {$number}: the cost center does not exist.");
        }

        if ($vatId !== null) {
            $code = DB::table('vat_codes')->where('id', $vatId)->where(static fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->whereNull('deleted_at')->first();

            if ($code === null) {
                throw new OcrExtractionException("Line {$number}: the VAT code does not exist.");
            }

            if (in_array($tenantCountry, ['CH', 'IT'], true) && strtoupper((string) $code->country_iso) !== $tenantCountry) {
                throw new OcrExtractionException("Line {$number}: the VAT code {$code->code} belongs to {$code->country_iso}, not to the {$tenantCountry} jurisdiction of the company.");
            }

            $vatRate ??= (float) ($code->tax_rate_percent ?? $code->rate);
            $vatNature = $code->nature;
        }

        return [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'net_amount' => $netAmount,
            'vat_rate' => $vatRate ?? 0.0,
            'vat_nature' => $vatNature,
            'gl_account_id' => $glId,
            'vat_code_id' => $vatId,
            'cost_center_id' => $costCenterId,
            'purchase_order_line_id' => isset($line['purchase_order_line_id']) && is_string($line['purchase_order_line_id']) ? $line['purchase_order_line_id'] : null,
            'rfq_line_id' => isset($line['rfq_line_id']) && is_string($line['rfq_line_id']) ? $line['rfq_line_id'] : null,
            'source_line_index' => isset($line['source_line_index']) && is_numeric($line['source_line_index']) ? (int) $line['source_line_index'] : $number - 1,
        ];
    }

    /**
     * @param  list<string>  $orderIds
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $reviewed
     * @return list<array<string, mixed>>
     */
    private function assertMatch(string $tenantId, ?string $vendorId, array $orderIds, array $lines, array $reviewed): array
    {
        $evaluations = [];

        foreach ($orderIds as $orderId) {
            $order = DB::table('purchase_orders')->where('tenant_id', $tenantId)->where('id', $orderId)->whereNull('deleted_at')->first();

            if ($order === null || ($vendorId !== null && $order->supplier_id !== $vendorId)) {
                throw new OcrExtractionException('A linked purchase order does not exist for this vendor.');
            }

            $evaluations[] = $this->matching->evaluate($tenantId, $orderId, array_map(static fn (array $line): array => ['description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price']], $lines));
        }

        $override = trim((string) ($reviewed['match_override_reason'] ?? ''));

        foreach ($evaluations as $evaluation) {
            if ($evaluation['status'] !== 'matched' && $override === '') {
                throw new OcrExtractionException("The invoice does not match purchase order {$evaluation['purchase_order_number']} within tolerance: review the variances or give an override reason.");
            }
        }

        return $evaluations;
    }

    private function visible(string $table, string $tenantId, string $id): bool
    {
        return DB::table($table)->where('id', $id)->where(static fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->exists();
    }
}

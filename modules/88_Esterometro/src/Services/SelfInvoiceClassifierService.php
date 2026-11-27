<?php

declare(strict_types=1);

namespace Modules\Esterometro\Services;

use App\Models\PassiveInvoice;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\TransactionNature;
use Modules\Esterometro\Models\SelfInvoiceClassificationRule;

/**
 * Deterministic classification engine for Italian Esterometro & Reverse Charge self-invoices (TD16–TD28).
 */
final class SelfInvoiceClassifierService
{
    /** @var list<string> */
    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
        'FR', 'GR', 'EL', 'HR', 'HU', 'IE', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * Classify a PassiveInvoice model and optionally apply a manual document type override.
     *
     * @return array{
     *     requires_self_invoice: bool,
     *     suggested_document_type: SelfInvoiceDocumentType|null,
     *     document_type: SelfInvoiceDocumentType|null,
     *     is_type_overridden: bool,
     *     supplier_country_code: string,
     *     supplier_region: string,
     *     transaction_nature: string,
     *     source_vat_nature: string|null,
     *     default_vat_rate: float,
     *     default_vat_nature: string|null,
     *     default_deductibility_percent: float,
     *     soggetto_emittente: string,
     *     matched_rule_code: string|null,
     *     legal_reference: string|null,
     *     compliance_warnings: list<array{code: string, severity: string, message: string}>
     * }
     */
    public function classifyPassiveInvoice(
        PassiveInvoice $invoice,
        ?SelfInvoiceDocumentType $overrideType = null,
        ?TransactionNature $overrideNature = null,
        ?string $regularizationTrigger = null,
    ): array {
        $invoice->loadMissing(['supplier', 'lines']);

        $countryCode = strtoupper(trim((string) ($invoice->supplier?->country_code ?? 'IT')));
        $vatNatures = $invoice->lines
            ->pluck('vat_nature')
            ->filter(static fn (mixed $v): bool => is_string($v) && trim($v) !== '')
            ->map(static fn (mixed $v): string => strtoupper(trim((string) $v)))
            ->values()
            ->all();

        $sourceVatNature = $vatNatures[0] ?? null;
        $hasN6 = false;
        foreach ($vatNatures as $nature) {
            if (str_starts_with($nature, 'N6')) {
                $hasN6 = true;
                $sourceVatNature = $nature;
                break;
            }
        }

        $nature = $overrideNature ?? $this->inferTransactionNature($countryCode, $hasN6, $regularizationTrigger, $invoice);

        return $this->classify(
            tenantId: $invoice->tenant_id !== null ? (string) $invoice->tenant_id : null,
            supplierCountryCode: $countryCode,
            transactionNature: $nature,
            sourceVatNature: $sourceVatNature,
            hasCustomsImportBill: (bool) ($invoice->getAttribute('has_customs_import_bill') ?? false),
            supplierChargedItalianVat: (float) ($invoice->vat_amount ?? 0) > 0 && $countryCode === 'SM',
            regularizationTrigger: $regularizationTrigger,
            overrideType: $overrideType,
        );
    }

    /**
     * Deterministic classification from explicit fiscal attributes.
     *
     * @return array{
     *     requires_self_invoice: bool,
     *     suggested_document_type: SelfInvoiceDocumentType|null,
     *     document_type: SelfInvoiceDocumentType|null,
     *     is_type_overridden: bool,
     *     supplier_country_code: string,
     *     supplier_region: string,
     *     transaction_nature: string,
     *     source_vat_nature: string|null,
     *     default_vat_rate: float,
     *     default_vat_nature: string|null,
     *     default_deductibility_percent: float,
     *     soggetto_emittente: string,
     *     matched_rule_code: string|null,
     *     legal_reference: string|null,
     *     compliance_warnings: list<array{code: string, severity: string, message: string}>
     * }
     */
    public function classify(
        ?string $tenantId,
        string $supplierCountryCode,
        TransactionNature $transactionNature,
        ?string $sourceVatNature = null,
        bool $hasCustomsImportBill = false,
        bool $supplierChargedItalianVat = false,
        ?string $regularizationTrigger = null,
        ?SelfInvoiceDocumentType $overrideType = null,
    ): array {
        $country = strtoupper(trim($supplierCountryCode)) ?: 'IT';
        $region = $this->resolveRegion($country);
        $normalizedVatNature = $sourceVatNature !== null ? strtoupper(trim($sourceVatNature)) : null;

        $warnings = [];

        if ($hasCustomsImportBill && $region !== 'IT') {
            $warnings[] = [
                'code' => 'CUSTOMS_IMPORT_BILL_PRESENT',
                'severity' => 'info',
                'message' => __('esterometro.warnings.customs_import_bill'),
            ];
        }

        $suggested = $this->determineSuggestedType(
            region: $region,
            country: $country,
            nature: $transactionNature,
            sourceVatNature: $normalizedVatNature,
            hasCustomsImportBill: $hasCustomsImportBill,
            regularizationTrigger: $regularizationTrigger,
        );

        $rule = $suggested !== null
            ? $this->findMatchingRule($tenantId, $suggested, $region, $country, $transactionNature, $normalizedVatNature)
            : null;

        $finalType = $overrideType ?? $suggested;
        $isOverridden = $overrideType !== null && $suggested !== null && $overrideType !== $suggested;

        if ($isOverridden) {
            $warnings[] = [
                'code' => 'MANUAL_TYPE_OVERRIDE',
                'severity' => 'warning',
                'message' => __('esterometro.warnings.manual_type_override', [
                    'suggested' => $suggested->value,
                    'selected' => $overrideType->value,
                ]),
            ];
        }

        if ($finalType === SelfInvoiceDocumentType::TD28 && $supplierChargedItalianVat) {
            $warnings[] = [
                'code' => 'SAN_MARINO_VAT_CHARGED',
                'severity' => 'info',
                'message' => __('esterometro.warnings.san_marino_vat_charged'),
            ];
        }

        if ($finalType !== null && $finalType->requiresForeignSupplier() && $region === 'IT') {
            $warnings[] = [
                'code' => 'FOREIGN_TYPE_WITH_IT_SUPPLIER',
                'severity' => 'warning',
                'message' => __('esterometro.warnings.foreign_type_with_it_supplier', ['type' => $finalType->value]),
            ];
        }

        if ($finalType === SelfInvoiceDocumentType::TD16 && $region !== 'IT') {
            $warnings[] = [
                'code' => 'TD16_WITH_FOREIGN_SUPPLIER',
                'severity' => 'warning',
                'message' => __('esterometro.warnings.td16_with_foreign_supplier'),
            ];
        }

        return [
            'requires_self_invoice' => $finalType !== null,
            'suggested_document_type' => $suggested,
            'document_type' => $finalType,
            'is_type_overridden' => $isOverridden,
            'supplier_country_code' => $country,
            'supplier_region' => $region,
            'transaction_nature' => $transactionNature->value,
            'source_vat_nature' => $normalizedVatNature,
            'default_vat_rate' => $rule !== null ? (float) $rule->default_vat_rate : 22.00,
            'default_vat_nature' => $rule?->default_vat_nature,
            'default_deductibility_percent' => $rule !== null ? (float) $rule->default_deductibility_percent : 100.00,
            'soggetto_emittente' => $rule?->soggetto_emittente ?? ($finalType?->soggettoEmittente() ?? 'CC'),
            'matched_rule_code' => $rule?->code,
            'legal_reference' => $rule?->legal_reference ?? $finalType?->legalReference(),
            'compliance_warnings' => $warnings,
        ];
    }

    public function resolveRegion(string $countryCode): string
    {
        $country = strtoupper(trim($countryCode));
        if ($country === 'IT') {
            return 'IT';
        }
        if ($country === 'SM') {
            return 'SM';
        }
        if (in_array($country, self::EU_COUNTRY_CODES, true)) {
            return 'EU';
        }

        return 'EXTRA_EU';
    }

    private function determineSuggestedType(
        string $region,
        string $country,
        TransactionNature $nature,
        ?string $sourceVatNature,
        bool $hasCustomsImportBill,
        ?string $regularizationTrigger,
    ): ?SelfInvoiceDocumentType {
        if ($regularizationTrigger !== null || $nature === TransactionNature::Regularization) {
            return SelfInvoiceDocumentType::TD20;
        }

        if ($country === 'SM' || $nature === TransactionNature::SanMarino) {
            return SelfInvoiceDocumentType::TD28;
        }

        if ($region === 'IT') {
            if ($nature === TransactionNature::DomesticRc || ($sourceVatNature !== null && str_starts_with($sourceVatNature, 'N6'))) {
                return SelfInvoiceDocumentType::TD16;
            }

            return null;
        }

        if ($hasCustomsImportBill && $nature === TransactionNature::Goods) {
            return null;
        }

        if ($nature === TransactionNature::GoodsInItaly) {
            return SelfInvoiceDocumentType::TD19;
        }

        if ($region === 'EU' && $nature === TransactionNature::Goods) {
            return SelfInvoiceDocumentType::TD18;
        }

        if ($region === 'EXTRA_EU' && $nature === TransactionNature::Goods) {
            return SelfInvoiceDocumentType::TD19;
        }

        return SelfInvoiceDocumentType::TD17;
    }

    private function inferTransactionNature(
        string $countryCode,
        bool $hasN6,
        ?string $regularizationTrigger,
        PassiveInvoice $invoice,
    ): TransactionNature {
        if ($regularizationTrigger !== null) {
            return TransactionNature::Regularization;
        }

        if ($countryCode === 'SM') {
            return TransactionNature::SanMarino;
        }

        if ($countryCode === 'IT') {
            return $hasN6 ? TransactionNature::DomesticRc : TransactionNature::Services;
        }

        $descriptions = strtolower($invoice->lines->pluck('description')->implode(' '));
        if (str_contains($descriptions, 'goods in italy') || str_contains($descriptions, 'beni in italia')) {
            return TransactionNature::GoodsInItaly;
        }

        $isGoods = (bool) ($invoice->getAttribute('is_goods') ?? false);

        return $isGoods ? TransactionNature::Goods : TransactionNature::Services;
    }

    private function findMatchingRule(
        ?string $tenantId,
        SelfInvoiceDocumentType $documentType,
        string $region,
        string $country,
        TransactionNature $nature,
        ?string $sourceVatNature,
    ): ?SelfInvoiceClassificationRule {
        $rules = SelfInvoiceClassificationRule::query()
            ->where('is_active', true)
            ->where('document_type', $documentType->value)
            ->when(
                $tenantId !== null,
                static fn ($q) => $q->where(static fn ($inner) => $inner->where('tenant_id', $tenantId)->orWhereNull('tenant_id')),
                static fn ($q) => $q->whereNull('tenant_id'),
            )
            ->orderByRaw('CASE WHEN tenant_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('priority')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->supplier_country_code !== null && strtoupper($rule->supplier_country_code) !== $country) {
                continue;
            }
            if ($rule->supplier_region !== 'ANY' && strtoupper($rule->supplier_region) !== $region) {
                continue;
            }
            if ($rule->transaction_nature !== 'ANY' && strtoupper((string) $rule->transaction_nature) !== $nature->value) {
                continue;
            }
            if ($rule->source_vat_nature_prefix !== null && ($sourceVatNature === null || ! str_starts_with($sourceVatNature, strtoupper($rule->source_vat_nature_prefix)))) {
                continue;
            }

            return $rule;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Esterometro\Services;

use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Esterometro\Domain\Enums\SdiTransmissionStatus;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\SelfInvoiceStatus;
use Modules\Esterometro\Domain\Enums\TransactionNature;
use Modules\Esterometro\Exceptions\EsterometroException;
use Modules\Esterometro\Models\SelfInvoice;
use Modules\Esterometro\Models\SelfInvoiceLine;
use Modules\Esterometro\Models\SelfInvoiceSdiLog;
use Modules\Esterometro\Models\SelfInvoiceSetting;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;

/**
 * Generates, validates, and rebuilds TD16–TD28 self-invoices from passive invoices or manual/TD20 entries.
 */
final class SelfInvoiceGeneratorService
{
    public function __construct(
        private readonly SelfInvoiceClassifierService $classifier,
        private readonly SelfInvoiceDeadlineMonitorService $deadlines,
        private readonly SelfInvoiceFatturaPaXmlBuilder $xmlBuilder,
        private readonly SelfInvoiceXmlValidator $xmlValidator,
        private readonly SelfInvoiceVatReconciliationService $vatReconciliation,
        private readonly ?RateSelector $rateSelector = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function generateFromPassiveInvoice(
        PassiveInvoice $passiveInvoice,
        ?string $actorUserId = null,
        array $options = [],
    ): SelfInvoice {
        $passiveInvoice->loadMissing(['supplier', 'lines']);
        $tenantId = (string) $passiveInvoice->tenant_id;

        $existing = SelfInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('passive_invoice_id', $passiveInvoice->id)
            ->where('status', '!=', SelfInvoiceStatus::Cancelled->value)
            ->first();

        if ($existing !== null && ! ($options['allow_duplicate'] ?? false)) {
            throw EsterometroException::duplicateSelfInvoice((string) $passiveInvoice->id);
        }

        $overrideType = isset($options['document_type'])
            ? SelfInvoiceDocumentType::from((string) $options['document_type'])
            : null;
        $overrideNature = isset($options['transaction_nature'])
            ? TransactionNature::from((string) $options['transaction_nature'])
            : null;
        $regularizationTrigger = isset($options['regularization_trigger']) ? (string) $options['regularization_trigger'] : null;

        $classification = $this->classifier->classifyPassiveInvoice(
            $passiveInvoice,
            $overrideType,
            $overrideNature,
            $regularizationTrigger,
        );

        $docType = $classification['document_type'];
        if ($docType === null) {
            throw EsterometroException::invalidStateTransition('PASSIVE_INVOICE', 'NO_SELF_INVOICE_REQUIRED');
        }

        $integrationDate = isset($options['integration_date'])
            ? Carbon::parse((string) $options['integration_date'])
            : Carbon::today();
        $receiptDate = $passiveInvoice->received_at !== null
            ? Carbon::parse($passiveInvoice->received_at)
            : ($passiveInvoice->invoice_date !== null ? Carbon::parse($passiveInvoice->invoice_date) : $integrationDate);
        $operationDate = $passiveInvoice->invoice_date !== null
            ? Carbon::parse($passiveInvoice->invoice_date)
            : $receiptDate;

        $currency = strtoupper(trim((string) ($passiveInvoice->currency ?: 'EUR')));
        $fx = $this->resolveExchangeRate(
            $currency,
            $integrationDate->toDateString(),
            $tenantId,
            isset($options['exchange_rate']) ? (float) $options['exchange_rate'] : null,
        );

        $linesPayload = [];
        $vatRateOverride = isset($options['vat_rate']) ? (float) $options['vat_rate'] : null;
        $deductibilityOverride = isset($options['deductibility_percent']) ? (float) $options['deductibility_percent'] : null;

        if ($passiveInvoice->lines->isNotEmpty()) {
            foreach ($passiveInvoice->lines as $index => $line) {
                $qty = max(0.0001, (float) ($line->quantity ?? 1));
                $taxableOrig = round((float) ($line->net_amount ?? $line->total_amount ?? 0), 2);
                $unitPriceOrig = (float) ($line->unit_price ?? ($taxableOrig / $qty));
                $vatRate = $vatRateOverride ?? $classification['default_vat_rate'];
                $deductibility = $deductibilityOverride ?? $classification['default_deductibility_percent'];

                $linesPayload[] = [
                    'passive_invoice_line_id' => $line->id,
                    'line_number' => $index + 1,
                    'description' => (string) ($line->description ?: "Item #{$index}"),
                    'transaction_nature' => $classification['transaction_nature'],
                    'quantity' => $qty,
                    'unit_price_original' => $unitPriceOrig,
                    'taxable_amount_original' => $taxableOrig,
                    'source_vat_nature' => $line->vat_nature,
                    'vat_rate' => $vatRate,
                    'vat_nature' => $vatRate === 0.0 ? ($classification['default_vat_nature'] ?? 'N2.2') : null,
                    'deductibility_percent' => $deductibility,
                    'normative_reference' => $classification['legal_reference'],
                ];
            }
        } else {
            $taxableOrig = round((float) ($passiveInvoice->net_amount ?? 0), 2);
            $vatRate = $vatRateOverride ?? $classification['default_vat_rate'];
            $deductibility = $deductibilityOverride ?? $classification['default_deductibility_percent'];
            $linesPayload[] = [
                'line_number' => 1,
                'description' => "Integrazione fattura {$passiveInvoice->invoice_number}",
                'transaction_nature' => $classification['transaction_nature'],
                'quantity' => 1.0,
                'unit_price_original' => $taxableOrig,
                'taxable_amount_original' => $taxableOrig,
                'source_vat_nature' => $classification['source_vat_nature'],
                'vat_rate' => $vatRate,
                'vat_nature' => $vatRate === 0.0 ? ($classification['default_vat_nature'] ?? 'N2.2') : null,
                'deductibility_percent' => $deductibility,
                'normative_reference' => $classification['legal_reference'],
            ];
        }

        $supplier = $passiveInvoice->supplier;

        return $this->persistSelfInvoice(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            attributes: [
                'passive_invoice_id' => $passiveInvoice->id,
                'supplier_id' => $supplier?->id,
                'document_type' => $docType,
                'suggested_document_type' => $classification['suggested_document_type'],
                'is_type_overridden' => $classification['is_type_overridden'],
                'override_reason' => $options['override_reason'] ?? null,
                'compliance_warnings' => $classification['compliance_warnings'],
                'integration_date' => $integrationDate,
                'operation_date' => $operationDate,
                'receipt_date' => $receiptDate,
                'regularization_trigger' => $regularizationTrigger,
                'supplier_country_code' => $classification['supplier_country_code'],
                'supplier_region' => $classification['supplier_region'],
                'supplier_name' => (string) ($supplier?->name ?? 'Fornitore Estero'),
                'supplier_vat_number' => $supplier?->vat_number,
                'supplier_fiscal_code' => $supplier?->tax_id,
                'supplier_address' => [
                    'street' => $supplier?->address,
                    'postal_code' => $supplier?->postal_code,
                    'city' => $supplier?->city,
                    'province' => $supplier?->province,
                ],
                'original_invoice_number' => $passiveInvoice->invoice_number,
                'original_invoice_date' => $passiveInvoice->invoice_date,
                'original_sdi_identifier' => $passiveInvoice->sdi_identificativo,
                'original_currency' => $currency,
                'original_net_amount' => (float) ($passiveInvoice->net_amount ?? 0),
                'original_vat_amount' => (float) ($passiveInvoice->vat_amount ?? 0),
                'original_gross_amount' => (float) ($passiveInvoice->total_amount ?? 0),
                'exchange_rate' => $fx['rate'],
                'exchange_rate_date' => $fx['date'],
                'exchange_rate_source' => $fx['source'],
                'soggetto_emittente' => $classification['soggetto_emittente'],
                'notes' => $options['notes'] ?? null,
            ],
            linesPayload: $linesPayload,
            validateAndReconcile: (bool) ($options['validate_and_reconcile'] ?? false),
        );
    }

    /**
     * Create a manual self-invoice (e.g. TD20 regularization or standalone TD17/TD19/TD28).
     *
     * @param  array<string, mixed>  $data
     */
    public function createManual(string $tenantId, ?string $actorUserId, array $data): SelfInvoice
    {
        $docType = SelfInvoiceDocumentType::from((string) $data['document_type']);
        $country = strtoupper(trim((string) ($data['supplier_country_code'] ?? 'IT')));
        $nature = isset($data['transaction_nature'])
            ? TransactionNature::from((string) $data['transaction_nature'])
            : $docType->defaultTransactionNature();

        $classification = $this->classifier->classify(
            tenantId: $tenantId,
            supplierCountryCode: $country,
            transactionNature: $nature,
            sourceVatNature: $data['source_vat_nature'] ?? null,
            hasCustomsImportBill: false,
            supplierChargedItalianVat: false,
            regularizationTrigger: $data['regularization_trigger'] ?? null,
            overrideType: $docType,
        );

        $integrationDate = Carbon::parse((string) ($data['integration_date'] ?? Carbon::today()->toDateString()));
        $operationDate = isset($data['operation_date']) ? Carbon::parse((string) $data['operation_date']) : $integrationDate;
        $receiptDate = isset($data['receipt_date']) ? Carbon::parse((string) $data['receipt_date']) : $integrationDate;

        $currency = strtoupper(trim((string) ($data['original_currency'] ?? 'EUR')));
        $fx = $this->resolveExchangeRate(
            $currency,
            $integrationDate->toDateString(),
            $tenantId,
            isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
        );

        $linesPayload = [];
        $netOrig = 0.0;
        foreach ($data['lines'] as $index => $line) {
            $qty = max(0.0001, (float) ($line['quantity'] ?? 1));
            $unitPriceOrig = (float) ($line['unit_price_original'] ?? 0);
            $taxableOrig = isset($line['taxable_amount_original'])
                ? (float) $line['taxable_amount_original']
                : round($qty * $unitPriceOrig, 2);
            $netOrig += $taxableOrig;

            $vatRate = (float) ($line['vat_rate'] ?? $classification['default_vat_rate']);
            $linesPayload[] = [
                'line_number' => $index + 1,
                'description' => (string) $line['description'],
                'transaction_nature' => (string) ($line['transaction_nature'] ?? $nature->value),
                'quantity' => $qty,
                'unit_price_original' => $unitPriceOrig,
                'taxable_amount_original' => $taxableOrig,
                'source_vat_nature' => $line['source_vat_nature'] ?? null,
                'vat_rate' => $vatRate,
                'vat_nature' => $vatRate === 0.0 ? ($line['vat_nature'] ?? 'N2.2') : null,
                'deductibility_percent' => (float) ($line['deductibility_percent'] ?? $classification['default_deductibility_percent']),
                'cost_account_id' => $line['cost_account_id'] ?? null,
                'normative_reference' => $line['normative_reference'] ?? $classification['legal_reference'],
            ];
        }

        return $this->persistSelfInvoice(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            attributes: [
                'passive_invoice_id' => $data['passive_invoice_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'document_type' => $docType,
                'suggested_document_type' => $classification['suggested_document_type'],
                'is_type_overridden' => $classification['is_type_overridden'],
                'override_reason' => $data['override_reason'] ?? null,
                'compliance_warnings' => $classification['compliance_warnings'],
                'integration_date' => $integrationDate,
                'operation_date' => $operationDate,
                'receipt_date' => $receiptDate,
                'regularization_trigger' => $data['regularization_trigger'] ?? null,
                'supplier_country_code' => $country,
                'supplier_region' => $classification['supplier_region'],
                'supplier_name' => (string) $data['supplier_name'],
                'supplier_vat_number' => $data['supplier_vat_number'] ?? null,
                'supplier_fiscal_code' => $data['supplier_fiscal_code'] ?? null,
                'supplier_address' => $data['supplier_address'] ?? null,
                'original_invoice_number' => $data['original_invoice_number'] ?? null,
                'original_invoice_date' => isset($data['original_invoice_date']) ? Carbon::parse((string) $data['original_invoice_date']) : null,
                'original_sdi_identifier' => $data['original_sdi_identifier'] ?? null,
                'original_currency' => $currency,
                'original_net_amount' => round($netOrig, 2),
                'original_vat_amount' => 0.0,
                'original_gross_amount' => round($netOrig, 2),
                'exchange_rate' => $fx['rate'],
                'exchange_rate_date' => $fx['date'],
                'exchange_rate_source' => $fx['source'],
                'soggetto_emittente' => $classification['soggetto_emittente'],
                'notes' => $data['notes'] ?? null,
            ],
            linesPayload: $linesPayload,
            validateAndReconcile: (bool) ($data['validate_and_reconcile'] ?? false),
        );
    }

    /**
     * Validate XML (and reconcile VAT registers if not yet reconciled).
     */
    public function validateAndBuildXml(SelfInvoice $selfInvoice, ?string $actorUserId = null, bool $reconcileVat = true): SelfInvoice
    {
        $tenant = Tenant::query()->find($selfInvoice->tenant_id);
        if ($tenant === null) {
            throw EsterometroException::missingTenantFiscalData('tenant');
        }

        $xml = $this->xmlBuilder->build($selfInvoice, $tenant);
        $validation = $this->xmlValidator->validate($xml, (string) $selfInvoice->tenant_id);

        if (! $validation['valid']) {
            throw EsterometroException::xmlValidationFailed(implode('; ', $validation['errors']));
        }

        $selfInvoice->forceFill([
            'xml_content' => $xml,
            'xml_sha256' => hash('sha256', $xml),
            'status' => SelfInvoiceStatus::Validated,
        ])->save();

        if ($reconcileVat && ! $selfInvoice->is_vat_reconciled) {
            $settings = SelfInvoiceSetting::query()->where('tenant_id', $selfInvoice->tenant_id)->first();
            if ($settings === null || $settings->auto_post_vat_registers) {
                $selfInvoice = $this->vatReconciliation->reconcile($selfInvoice, $actorUserId);
            }
        }

        return $selfInvoice->fresh(['lines', 'sdiLogs']) ?? $selfInvoice;
    }

    /**
     * Update an existing draft/rejected self-invoice and rebuild XML.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAndRebuild(SelfInvoice $selfInvoice, ?string $actorUserId, array $data): SelfInvoice
    {
        if (! in_array($selfInvoice->status, [SelfInvoiceStatus::Draft, SelfInvoiceStatus::Validated, SelfInvoiceStatus::RejectedNs, SelfInvoiceStatus::FailedTransmission], true)) {
            throw EsterometroException::invalidStateTransition($selfInvoice->status->value, 'UPDATE');
        }

        return DB::transaction(function () use ($selfInvoice, $actorUserId, $data): SelfInvoice {
            $updates = [];
            foreach ([
                'document_type', 'override_reason', 'supplier_name', 'supplier_vat_number',
                'supplier_fiscal_code', 'supplier_country_code', 'supplier_address',
                'original_invoice_number', 'original_invoice_date', 'original_sdi_identifier',
                'recipient_code', 'notes',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            if (isset($updates['document_type'])) {
                $newType = SelfInvoiceDocumentType::from((string) $updates['document_type']);
                $updates['document_type'] = $newType;
                $updates['is_type_overridden'] = $selfInvoice->suggested_document_type !== null
                    && $newType !== $selfInvoice->suggested_document_type;
            }

            if ($updates !== []) {
                $selfInvoice->fill($updates)->save();
            }

            $tenant = Tenant::query()->find($selfInvoice->tenant_id);
            if ($tenant !== null) {
                $xml = $this->xmlBuilder->build($selfInvoice->fresh('lines') ?? $selfInvoice, $tenant);
                $selfInvoice->forceFill([
                    'xml_content' => $xml,
                    'xml_sha256' => hash('sha256', $xml),
                    'status' => SelfInvoiceStatus::Validated,
                    'sdi_status' => SdiTransmissionStatus::Draft,
                ])->save();
            }

            SelfInvoiceSdiLog::create([
                'tenant_id' => $selfInvoice->tenant_id,
                'self_invoice_id' => $selfInvoice->id,
                'event_type' => 'UPDATED_FOR_RETRANSMISSION',
                'from_status' => $selfInvoice->status->value,
                'to_status' => SelfInvoiceStatus::Validated->value,
                'created_by' => $actorUserId,
            ]);

            return $selfInvoice->fresh(['lines', 'sdiLogs']) ?? $selfInvoice;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $linesPayload
     */
    private function persistSelfInvoice(
        string $tenantId,
        ?string $actorUserId,
        array $attributes,
        array $linesPayload,
        bool $validateAndReconcile,
    ): SelfInvoice {
        $settings = SelfInvoiceSetting::query()->where('tenant_id', $tenantId)->first();
        $tenant = Tenant::query()->find($tenantId);

        /** @var SelfInvoiceDocumentType $docType */
        $docType = $attributes['document_type'];
        /** @var Carbon $integrationDate */
        $integrationDate = $attributes['integration_date'];
        $fiscalYear = $integrationDate->year;
        $exchangeRate = (float) $attributes['exchange_rate'];

        $deadline = $this->deadlines->computeTransmissionDeadline(
            documentType: $docType,
            integrationDate: $integrationDate,
            operationDate: $attributes['operation_date'] ?? null,
            receiptDate: $attributes['receipt_date'] ?? null,
            regularizationTrigger: $attributes['regularization_trigger'] ?? null,
        );

        $recipientCode = '0000000';
        if ($settings?->use_tenant_sdi_code && $tenant !== null && ! empty($tenant->sdi_code)) {
            $recipientCode = (string) $tenant->sdi_code;
        } elseif ($settings !== null && $settings->default_recipient_code !== '') {
            $recipientCode = $settings->default_recipient_code;
        }

        $selfInvoice = DB::transaction(function () use (
            $tenantId,
            $actorUserId,
            $attributes,
            $linesPayload,
            $fiscalYear,
            $exchangeRate,
            $deadline,
            $recipientCode,
            $settings,
        ): SelfInvoice {
            $maxSeq = (int) SelfInvoice::query()
                ->where('tenant_id', $tenantId)
                ->where('fiscal_year', $fiscalYear)
                ->max('sequential_number');
            $nextSeq = $maxSeq + 1;

            $prefix = $settings?->numbering_prefix ?? 'AF-';
            $padding = $settings?->numbering_padding ?? 5;
            $docNumber = $prefix.$fiscalYear.'-'.str_pad((string) $nextSeq, $padding, '0', STR_PAD_LEFT);

            $taxableEurTotal = 0.0;
            $vatEurTotal = 0.0;
            $deductibleEurTotal = 0.0;
            $nonDeductibleEurTotal = 0.0;

            $computedLines = [];
            foreach ($linesPayload as $line) {
                $taxableOrig = (float) $line['taxable_amount_original'];
                $taxableEur = round($taxableOrig * $exchangeRate, 2);
                $unitPriceEur = round(((float) $line['unit_price_original']) * $exchangeRate, 4);
                $vatRate = (float) $line['vat_rate'];
                $vatEur = round($taxableEur * ($vatRate / 100.0), 2);
                $deductPct = max(0.0, min(100.0, (float) $line['deductibility_percent']));
                $deductibleEur = round($vatEur * ($deductPct / 100.0), 2);
                $nonDeductibleEur = round($vatEur - $deductibleEur, 2);

                $taxableEurTotal += $taxableEur;
                $vatEurTotal += $vatEur;
                $deductibleEurTotal += $deductibleEur;
                $nonDeductibleEurTotal += $nonDeductibleEur;

                $computedLines[] = $line + [
                    'unit_price_eur' => $unitPriceEur,
                    'taxable_amount_eur' => $taxableEur,
                    'vat_amount_eur' => $vatEur,
                    'deductible_vat_eur' => $deductibleEur,
                    'non_deductible_vat_eur' => $nonDeductibleEur,
                ];
            }

            $grossEurTotal = round($taxableEurTotal + $vatEurTotal, 2);

            /** @var SelfInvoice $created */
            $created = SelfInvoice::create([
                'tenant_id' => $tenantId,
                'passive_invoice_id' => $attributes['passive_invoice_id'] ?? null,
                'supplier_id' => $attributes['supplier_id'] ?? null,
                'document_type' => $attributes['document_type'],
                'suggested_document_type' => $attributes['suggested_document_type'],
                'is_type_overridden' => $attributes['is_type_overridden'],
                'override_reason' => $attributes['override_reason'] ?? null,
                'compliance_warnings' => $attributes['compliance_warnings'] ?? [],
                'status' => SelfInvoiceStatus::Draft,
                'sdi_status' => SdiTransmissionStatus::Draft,
                'document_number' => $docNumber,
                'fiscal_year' => $fiscalYear,
                'sequential_number' => $nextSeq,
                'sectional_code' => $settings?->sales_vat_section_code ?? 'VEN-AUT',
                'integration_date' => $attributes['integration_date']->toDateString(),
                'operation_date' => $attributes['operation_date']?->toDateString(),
                'receipt_date' => $attributes['receipt_date']?->toDateString(),
                'transmission_deadline_date' => $deadline->toDateString(),
                'regularization_trigger' => $attributes['regularization_trigger'] ?? null,
                'supplier_country_code' => $attributes['supplier_country_code'],
                'supplier_region' => $attributes['supplier_region'],
                'supplier_name' => $attributes['supplier_name'],
                'supplier_vat_number' => $attributes['supplier_vat_number'] ?? null,
                'supplier_fiscal_code' => $attributes['supplier_fiscal_code'] ?? null,
                'supplier_address' => $attributes['supplier_address'] ?? null,
                'original_invoice_number' => $attributes['original_invoice_number'] ?? null,
                'original_invoice_date' => $attributes['original_invoice_date']?->toDateString(),
                'original_sdi_identifier' => $attributes['original_sdi_identifier'] ?? null,
                'original_currency' => $attributes['original_currency'],
                'original_net_amount' => $attributes['original_net_amount'],
                'original_vat_amount' => $attributes['original_vat_amount'],
                'original_gross_amount' => $attributes['original_gross_amount'],
                'exchange_rate' => $exchangeRate,
                'exchange_rate_date' => $attributes['exchange_rate_date'],
                'exchange_rate_source' => $attributes['exchange_rate_source'],
                'currency' => 'EUR',
                'taxable_amount_eur' => round($taxableEurTotal, 2),
                'vat_amount_eur' => round($vatEurTotal, 2),
                'deductible_vat_eur' => round($deductibleEurTotal, 2),
                'non_deductible_vat_eur' => round($nonDeductibleEurTotal, 2),
                'gross_amount_eur' => $grossEurTotal,
                'soggetto_emittente' => $attributes['soggetto_emittente'],
                'recipient_code' => $recipientCode,
                'sdi_progressive' => str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT),
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actorUserId,
            ]);

            foreach ($computedLines as $lineData) {
                SelfInvoiceLine::create($lineData + [
                    'tenant_id' => $tenantId,
                    'self_invoice_id' => $created->id,
                ]);
            }

            SelfInvoiceSdiLog::create([
                'tenant_id' => $tenantId,
                'self_invoice_id' => $created->id,
                'event_type' => 'CREATED',
                'from_status' => null,
                'to_status' => SelfInvoiceStatus::Draft->value,
                'created_by' => $actorUserId,
            ]);

            return $created;
        });

        if ($tenant !== null) {
            $xml = $this->xmlBuilder->build($selfInvoice->fresh('lines') ?? $selfInvoice, $tenant);
            $selfInvoice->forceFill([
                'xml_content' => $xml,
                'xml_sha256' => hash('sha256', $xml),
            ])->save();
        }

        if ($validateAndReconcile) {
            return $this->validateAndBuildXml($selfInvoice, $actorUserId, true);
        }

        return $selfInvoice->fresh(['lines', 'sdiLogs']) ?? $selfInvoice;
    }

    /**
     * @return array{rate: float, date: string, source: string}
     */
    private function resolveExchangeRate(
        string $currency,
        string $date,
        string $tenantId,
        ?float $manualRate = null,
    ): array {
        if ($currency === 'EUR') {
            return ['rate' => 1.0, 'date' => $date, 'source' => 'EUR'];
        }

        if ($manualRate !== null && $manualRate > 0.0) {
            return ['rate' => $manualRate, 'date' => $date, 'source' => 'manual'];
        }

        try {
            $selector = $this->rateSelector ?? app(RateSelector::class);
            $resolved = $selector->select(CurrencyPair::of($currency, 'EUR'), $date, $tenantId);

            return [
                'rate' => (float) $resolved->rate->value,
                'date' => $resolved->validOn,
                'source' => 'ECB',
            ];
        } catch (ExchangeRateMissingException) {
            throw EsterometroException::fxRateUnavailable($currency, $date);
        }
    }
}

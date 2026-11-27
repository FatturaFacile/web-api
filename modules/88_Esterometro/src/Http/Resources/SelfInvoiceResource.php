<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Esterometro\Models\SelfInvoice;
use Modules\Esterometro\Services\SelfInvoiceDeadlineMonitorService;

/**
 * @mixin SelfInvoice
 */
final class SelfInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SelfInvoice $model */
        $model = $this->resource;
        $urgency = app(SelfInvoiceDeadlineMonitorService::class)->computeUrgency($model);

        return [
            'id' => $model->id,
            'tenant_id' => $model->tenant_id,
            'passive_invoice_id' => $model->passive_invoice_id,
            'supplier_id' => $model->supplier_id,
            'document_type' => $model->document_type->value,
            'document_type_label' => $model->document_type->description(),
            'legal_reference' => $model->document_type->legalReference(),
            'suggested_document_type' => $model->suggested_document_type?->value,
            'is_type_overridden' => $model->is_type_overridden,
            'override_reason' => $model->override_reason,
            'compliance_warnings' => $model->compliance_warnings ?? [],
            'status' => $model->status->value,
            'sdi_status' => $model->sdi_status->value,
            'deadline_urgency' => $urgency->value,
            'document_number' => $model->document_number,
            'fiscal_year' => $model->fiscal_year,
            'sequential_number' => $model->sequential_number,
            'sectional_code' => $model->sectional_code,
            'integration_date' => $model->integration_date->toDateString(),
            'operation_date' => $model->operation_date?->toDateString(),
            'receipt_date' => $model->receipt_date?->toDateString(),
            'transmission_deadline_date' => $model->transmission_deadline_date->toDateString(),
            'regularization_trigger' => $model->regularization_trigger,
            'supplier_country_code' => $model->supplier_country_code,
            'supplier_region' => $model->supplier_region,
            'supplier_name' => $model->supplier_name,
            'supplier_vat_number' => $model->supplier_vat_number,
            'supplier_fiscal_code' => $model->supplier_fiscal_code,
            'supplier_address' => $model->supplier_address,
            'original_invoice_number' => $model->original_invoice_number,
            'original_invoice_date' => $model->original_invoice_date?->toDateString(),
            'original_sdi_identifier' => $model->original_sdi_identifier,
            'original_currency' => $model->original_currency,
            'original_net_amount' => (float) $model->original_net_amount,
            'original_vat_amount' => (float) $model->original_vat_amount,
            'original_gross_amount' => (float) $model->original_gross_amount,
            'exchange_rate' => (float) $model->exchange_rate,
            'exchange_rate_date' => $model->exchange_rate_date?->toDateString(),
            'exchange_rate_source' => $model->exchange_rate_source,
            'currency' => $model->currency,
            'taxable_amount_eur' => (float) $model->taxable_amount_eur,
            'vat_amount_eur' => (float) $model->vat_amount_eur,
            'deductible_vat_eur' => (float) $model->deductible_vat_eur,
            'non_deductible_vat_eur' => (float) $model->non_deductible_vat_eur,
            'gross_amount_eur' => (float) $model->gross_amount_eur,
            'soggetto_emittente' => $model->soggetto_emittente,
            'recipient_code' => $model->recipient_code,
            'sdi_identificativo' => $model->sdi_identificativo,
            'sdi_filename' => $model->sdi_filename,
            'xml_content' => $this->when($request->boolean('include_xml') || $request->routeIs('*.show'), $model->xml_content),
            'xml_sha256' => $model->xml_sha256,
            'sent_at' => $model->sent_at?->toIso8601String(),
            'delivered_at' => $model->delivered_at?->toIso8601String(),
            'rejected_at' => $model->rejected_at?->toIso8601String(),
            'rejection_code' => $model->rejection_code,
            'rejection_description' => $model->rejection_description,
            'sales_vat_entry_id' => $model->sales_vat_entry_id,
            'purchase_vat_entry_id' => $model->purchase_vat_entry_id,
            'journal_entry_id' => $model->journal_entry_id,
            'is_vat_reconciled' => $model->is_vat_reconciled,
            'notes' => $model->notes,
            'lines' => $this->whenLoaded('lines', static fn (): array => $model->lines->map(static fn ($l): array => [
                'id' => $l->id,
                'line_number' => $l->line_number,
                'description' => $l->description,
                'transaction_nature' => $l->transaction_nature->value,
                'quantity' => (float) $l->quantity,
                'unit_price_original' => (float) $l->unit_price_original,
                'unit_price_eur' => (float) $l->unit_price_eur,
                'taxable_amount_original' => (float) $l->taxable_amount_original,
                'taxable_amount_eur' => (float) $l->taxable_amount_eur,
                'source_vat_nature' => $l->source_vat_nature,
                'vat_rate' => (float) $l->vat_rate,
                'vat_nature' => $l->vat_nature,
                'vat_amount_eur' => (float) $l->vat_amount_eur,
                'deductibility_percent' => (float) $l->deductibility_percent,
                'deductible_vat_eur' => (float) $l->deductible_vat_eur,
                'non_deductible_vat_eur' => (float) $l->non_deductible_vat_eur,
                'normative_reference' => $l->normative_reference,
            ])->all()),
            'sdi_logs' => $this->whenLoaded('sdiLogs', static fn (): array => $model->sdiLogs->map(static fn ($log): array => [
                'id' => $log->id,
                'event_type' => $log->event_type,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'sdi_identificativo' => $log->sdi_identificativo,
                'sdi_filename' => $log->sdi_filename,
                'error_code' => $log->error_code,
                'error_description' => $log->error_description,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all()),
            'created_at' => $model->created_at?->toIso8601String(),
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }
}

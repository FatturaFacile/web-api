<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;

/**
 * @mixin VanVisitOutcome
 */
final class VanVisitOutcomeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'field_route_plan_id' => $this->field_route_plan_id,
            'van_warehouse_id' => $this->van_warehouse_id,
            'van_license_plate' => $this->whenLoaded('vanWarehouse', fn (): ?string => $this->vanWarehouse?->license_plate),
            'van_load_manifest_id' => $this->van_load_manifest_id,
            'contact_id' => $this->contact_id,
            'customer_name' => $this->whenLoaded('contact', function (): string {
                $contact = $this->contact;

                return $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));
            }),
            'stop_sequence' => $this->stop_sequence,
            'visit_mode' => $this->visit_mode->value,
            'outcome_status' => $this->outcome_status->value,
            'no_sale_reason_code' => $this->no_sale_reason_code,
            'rescheduled_to' => $this->rescheduled_to?->toDateString(),
            'document_type' => $this->document_type?->value,
            'fiscal_sectional_code' => $this->fiscal_sectional_code,
            'definitive_document_number' => $this->definitive_document_number,
            'invoice_id' => $this->invoice_id,
            'delivery_note_id' => $this->delivery_note_id,
            'sales_order_id' => $this->sales_order_id,
            'sdi_transmission_status' => $this->sdi_transmission_status,
            'qr_bill_generated' => $this->qr_bill_generated,
            'qr_bill_reference' => $this->qr_bill_reference,
            'credit_check_passed' => $this->credit_check_passed,
            'credit_limit_amount' => $this->credit_limit_amount,
            'exposure_before_visit' => $this->exposure_before_visit,
            'overdue_amount_before_visit' => $this->overdue_amount_before_visit,
            'credit_blocked_initially' => $this->credit_blocked_initially,
            'unblocked_by_collection' => $this->unblocked_by_collection,
            'collected_overdue_amount' => $this->collected_overdue_amount,
            'sale_lines' => $this->sale_lines ?? [],
            'return_lines' => $this->return_lines ?? [],
            'empties_movements' => $this->empties_movements ?? [],
            'collections' => $this->collections ?? [],
            'total_net' => $this->total_net,
            'total_tax' => $this->total_tax,
            'total_gross' => $this->total_gross,
            'empties_net_deposit_amount' => $this->empties_net_deposit_amount,
            'returns_deduction_amount' => $this->returns_deduction_amount,
            'total_collected_amount' => $this->total_collected_amount,
            'payment_method' => $this->payment_method,
            'currency' => $this->currency,
            'printer_format' => $this->printer_format->value,
            'client_idempotency_key' => $this->client_idempotency_key,
            'offline_created_at' => $this->offline_created_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'sync_version' => $this->sync_version,
            'visited_at' => $this->visited_at->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WithholdingSettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'year' => $this->year,
            'month' => $this->month,
            'currency' => $this->currency,
            'total_withheld' => (string) $this->total_withheld,
            'total_f24' => (string) $this->total_f24,
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'payment_date' => $this->payment_date?->toDateString(),
            'payment_journal_id' => $this->payment_journal_id,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'passive_invoice_id' => (string) $line->passive_invoice_id,
                'supplier_payment_id' => (string) $line->supplier_payment_id,
                'supplier_id' => (string) $line->supplier_id,
                'invoice_number' => $line->invoice_number,
                'paid_at' => $line->paid_at?->toDateString(),
                'withholding_type' => $line->withholding_type,
                'reason' => $line->reason,
                'f24_code' => $line->f24_code,
                'gross_paid' => (string) $line->gross_paid,
                'taxable_amount' => (string) $line->taxable_amount,
                'rate_percent' => $line->rate_percent !== null ? (string) $line->rate_percent : null,
                'withheld_amount' => (string) $line->withheld_amount,
                'detail_assumed' => (bool) $line->detail_assumed,
            ])->all()),
        ];
    }
}

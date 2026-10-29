<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'expense_number' => $this->expense_number,
            'date' => $this->date?->toDateString(),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'code' => $this->category->code,
                'name' => $this->category->translations->firstWhere('locale', app()->getLocale())?->label
                    ?? $this->category->translations->firstWhere('locale', 'en')?->label
                    ?? $this->category->code,
            ]),
            'amount_net' => (float) $this->amount_net,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'amount_gross' => (float) $this->amount_gross,
            'currency' => $this->currency,
            'merchant_name' => $this->merchant_name,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'is_billable' => $this->is_billable,
            'is_reimbursable' => $this->is_reimbursable,
            'notes' => $this->notes,
            'accounting_exported_at' => $this->accounting_exported_at?->toISOString(),
            'is_exported' => $this->isExported(),
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact->id,
                'name' => $this->contact->company_name ?? ($this->contact->first_name.' '.$this->contact->last_name),
                'email' => $this->contact->email,
                'vat_number' => $this->contact->vat_number,
            ]),
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'project_id' => $this->project_id,
            'ticket_id' => $this->ticket_id,
            'ticket' => $this->whenLoaded('ticket', fn () => [
                'id' => $this->ticket->id,
                'subject' => $this->ticket->subject,
                'status' => $this->ticket->status,
            ]),
            'service_report_id' => $this->service_report_id,
            'service_report' => $this->whenLoaded('serviceReport', fn () => [
                'id' => $this->serviceReport->id,
                'report_number' => $this->serviceReport->report_number,
                'status' => $this->serviceReport->status,
            ]),
            'attachments' => ExpenseAttachmentResource::collection($this->whenLoaded('expenseAttachments')),
            'attachments_count' => $this->whenCounted('expenseAttachments', $this->expense_attachments_count),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

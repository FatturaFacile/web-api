<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\LandedCostDocumentDTO;
use Modules\Inventory\Enums\LandedCostAllocationKey;
use Modules\Inventory\Enums\LandedCostType;
use Modules\Inventory\Support\Decimal;

/**
 * Body of `POST landed-costs` and `POST landed-costs/preview`.
 */
final class StoreLandedCostDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'document_date' => ['required', 'date'],
            'cost_type' => ['required', 'string', Rule::in(LandedCostType::values())],
            'allocation_key' => ['required', 'string', Rule::in(LandedCostAllocationKey::values())],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['sometimes', 'numeric', 'gt:0'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'supplier_invoice_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt_ids' => ['required', 'array', 'min:1'],
            'receipt_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('purchase_receipts', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
        ];
    }

    public function toDTO(): LandedCostDocumentDTO
    {
        /** @var list<string> $receiptIds */
        $receiptIds = array_values(array_map('strval', (array) $this->input('receipt_ids', [])));

        return new LandedCostDocumentDTO(
            documentDate: $this->string('document_date')->value(),
            costType: LandedCostType::from($this->string('cost_type')->value()),
            allocationKey: LandedCostAllocationKey::from($this->string('allocation_key')->value()),
            currency: strtoupper($this->string('currency')->value()),
            totalAmount: Decimal::money((string) $this->input('total_amount')),
            receiptIds: $receiptIds,
            supplierInvoiceId: $this->filled('supplier_invoice_id') ? $this->string('supplier_invoice_id')->value() : null,
            notes: $this->filled('notes') ? $this->string('notes')->value() : null,
            exchangeRate: $this->filled('exchange_rate') ? Decimal::of((string) $this->input('exchange_rate')) : '1',
            userId: $this->user()?->getAuthIdentifier() !== null ? (string) $this->user()->getAuthIdentifier() : null,
        );
    }
}

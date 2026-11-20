<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;

/**
 * The reviewed document of the workbench: header, lines with their coding, and the purchase orders and receipts linked.
 * The business rules (periods, VAT jurisdiction, totals, match tolerance) are checked by the confirm handler.
 */
final class ReviewedDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = (string) app(TenantContext::class)->getTenantId();
        $tenantUuid = static fn (string $table) => Rule::exists($table, 'id')->where(static fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'));

        return [
            'category' => ['nullable', Rule::enum(DocumentCategory::class)],
            'vendor_id' => ['nullable', 'uuid', $tenantUuid('contacts')],
            'header' => ['required', 'array'],
            'header.number' => ['nullable', 'string', 'max:100'],
            'header.issue_date' => ['required', 'date'],
            'header.due_date' => ['nullable', 'date'],
            'header.currency' => ['required', 'string', 'size:3'],
            'header.qr_reference' => ['nullable', 'string', 'max:50'],
            'totals' => ['nullable', 'array'],
            'totals.net' => ['nullable', 'numeric'],
            'totals.vat' => ['nullable', 'numeric'],
            'totals.gross' => ['nullable', 'numeric'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['nullable', 'numeric'],
            'lines.*.unit_price' => ['nullable', 'numeric'],
            'lines.*.net_amount' => ['nullable', 'numeric'],
            'lines.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.gl_account_id' => ['nullable', 'uuid'],
            'lines.*.vat_code_id' => ['nullable', 'uuid'],
            'lines.*.cost_center_id' => ['nullable', 'uuid'],
            'lines.*.purchase_order_line_id' => ['nullable', 'uuid'],
            'lines.*.rfq_line_id' => ['nullable', 'uuid'],
            'lines.*.source_line_index' => ['nullable', 'integer', 'min:0'],
            'purchase_order_ids' => ['nullable', 'array'],
            'purchase_order_ids.*' => ['uuid'],
            'goods_receipt_ids' => ['nullable', 'array'],
            'goods_receipt_ids.*' => ['uuid'],
            'match_override_reason' => ['nullable', 'string', 'max:500'],
            'expense_category_id' => ['nullable', 'uuid'],
            'rfq_id' => ['nullable', 'uuid'],
            'warehouse_id' => ['nullable', 'uuid'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\Plm\Models\PlmChangeOrder;

/**
 * A change order with its approvers (in the order they decide), affected items and affected bills of material. Each
 * list is replaced as a whole when sent; they can only change while the order is a draft.
 */
final class ChangeOrderRequest extends PlmFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'code' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:5000'],
            'change_type' => ['sometimes', Rule::in(PlmChangeOrder::ChangeTypes)],
            'priority' => ['sometimes', Rule::in(PlmChangeOrder::Priorities)],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'approvers' => ['sometimes', 'array', 'max:10'],
            'approvers.*' => ['uuid', 'distinct', Rule::exists('tenant_user', 'user_id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())],
            'affected_items' => ['sometimes', 'array', 'max:100'],
            'affected_items.*.mfg_item_id' => ['required', 'uuid', $this->ownRow('mfg_items')],
            'affected_items.*.to_revision' => ['required', 'string', 'max:20'],
            'affected_items.*.change_summary' => ['nullable', 'string', 'max:2000'],
            'affected_boms' => ['sometimes', 'array', 'max:100'],
            'affected_boms.*.bom_id' => ['required', 'uuid', $this->ownRow('mfg_boms')],
            'affected_boms.*.note' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\Plm\Enums\ChangeReason;
use Modules\Plm\Enums\StockDispositionRule;
use Modules\Plm\Models\PlmChangeOrder;

/**
 * A change order with its approvers, affected items (with per-item disposition/cutover overrides)
 * and affected bills of material.
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
            'change_reason' => ['sometimes', Rule::in(ChangeReason::values())],
            'is_safety_immediate_cutover' => ['sometimes', 'boolean'],
            'existing_stock_disposition_rule' => ['sometimes', Rule::in(StockDispositionRule::values())],
            'effective_date_or_lot_cutover' => ['nullable', 'string', 'max:120'],
            'lot_serial_cutover_code' => ['nullable', 'string', 'max:80'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'approvers' => ['sometimes', 'array', 'max:10'],
            'approvers.*' => ['uuid', 'distinct', Rule::exists('tenant_user', 'user_id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())],
            'affected_items' => ['sometimes', 'array', 'max:100'],
            'affected_items.*.mfg_item_id' => ['required', 'uuid', $this->ownRow('mfg_items')],
            'affected_items.*.to_revision' => ['required', 'string', 'max:20'],
            'affected_items.*.change_summary' => ['nullable', 'string', 'max:2000'],
            'affected_items.*.disposition_rule' => ['nullable', Rule::in(StockDispositionRule::values())],
            'affected_items.*.cutover_mode' => ['nullable', 'string', 'max:40'],
            'affected_items.*.effective_date' => ['nullable', 'date_format:Y-m-d'],
            'affected_items.*.lot_serial_cutover' => ['nullable', 'string', 'max:80'],
            'affected_boms' => ['sometimes', 'array', 'max:100'],
            'affected_boms.*.bom_id' => ['required', 'uuid', $this->ownRow('mfg_boms')],
            'affected_boms.*.note' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

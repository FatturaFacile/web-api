<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hospitality\Models\OrderItem;

/**
 * Rooms, tables, orders, kitchen tickets and payments. Each route names the payload it validates.
 */
final class HospitalityRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return match (str_replace('api.v1.hospitality.', '', (string) $this->route()?->getName())) {
            'rooms.store' => ['name' => ['required', 'string', 'max:100'], 'sort_order' => ['sometimes', 'integer', 'min:0'], 'width' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'height' => ['sometimes', 'integer', 'min:1', 'max:10000']],
            'rooms.tables.store', 'tables.update' => [
                'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:30'],
                'seats' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'shape' => ['sometimes', 'string', 'in:square,round,rectangle'],
                'pos_x' => ['sometimes', 'integer', 'min:0', 'max:10000'],
                'pos_y' => ['sometimes', 'integer', 'min:0', 'max:10000'],
                'width' => ['sometimes', 'integer', 'min:1', 'max:10000'],
                'height' => ['sometimes', 'integer', 'min:1', 'max:10000'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'orders.store' => ['table_id' => ['required', 'uuid', $this->tenantOwns('hosp_tables')], 'covers' => ['required', 'integer', 'min:1', 'max:100'], 'notes' => ['nullable', 'string', 'max:500']],
            'orders.items.store' => [
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'items.*.product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
                'items.*.description' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:255'],
                'items.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
                'items.*.unit_price' => ['required_without:items.*.product_id', 'nullable', 'decimal:0,4', 'min:0'],
                'items.*.tax_rate' => ['required_without:items.*.product_id', 'nullable', 'decimal:0,2', 'min:0', 'max:100'],
                'items.*.course' => ['sometimes', 'integer', 'min:1', 'max:9'],
                'items.*.station' => ['sometimes', Rule::in(OrderItem::Stations)],
                'items.*.notes' => ['nullable', 'string', 'max:255'],
            ],
            'orders.fire' => ['course' => ['required', 'integer', 'min:1', 'max:9']],
            'orders.move' => ['table_id' => ['required', 'uuid', $this->tenantOwns('hosp_tables')]],
            'items.void' => ['reason' => ['required', 'string', 'min:3', 'max:255']],
            'orders.payments.store' => [
                'pos_session_id' => ['required', 'uuid', Rule::exists('pos_sessions', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
                'mode' => ['required', 'string', 'in:items,equal'],
                'lines' => ['required_if:mode,items', 'array', 'min:1'],
                'lines.*.order_item_id' => ['required', 'uuid', 'distinct'],
                'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
                'ways' => ['required_if:mode,equal', 'integer', 'min:1', 'max:100'],
                'payment_method' => ['required', 'string', 'in:cash,card,mixed'],
                'cash_amount' => ['nullable', 'required_if:payment_method,mixed', 'numeric', 'gt:0'],
                'cash_tendered' => ['nullable', 'numeric', 'min:0'],
                'contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            ],
            'kitchen-tickets.index' => ['station' => ['sometimes', Rule::in(OrderItem::Stations)], 'status' => ['sometimes', 'string', 'in:open,ready,served']],
            'kitchen-tickets.advance' => ['status' => ['required', 'string', 'in:ready,served']],
            default => [],
        };
    }
}

<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the manufacturing form requests: references are validated against the active company, access is decided by
 * the module.access middleware and the policies of the controllers.
 */
abstract class ManufacturingRequest extends FormRequest
{
    use TenantScopedRules;

    /** Rules of a positive quantity with the 4 decimals of the stock ledger. */
    protected const array PositiveQuantity = ['numeric', 'decimal:0,4', 'gt:0', 'max:99999999999'];

    /** Rules of a quantity that may be zero. */
    protected const array Quantity = ['numeric', 'decimal:0,4', 'min:0', 'max:99999999999'];

    /** Rules of a duration in minutes. */
    protected const array Minutes = ['numeric', 'decimal:0,2', 'min:0', 'max:9999999'];

    /** Rules of a cost or rate. */
    protected const array Amount = ['numeric', 'decimal:0,4', 'min:0', 'max:99999999999'];

    /**
     * Access is decided by the module.access middleware and the policies of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules of a list of BOM component changes (add, update, remove) on items of the company.
     *
     * @return array<string, mixed>
     */
    protected function componentChangeRules(string $key): array
    {
        return [
            $key.'.*.action' => ['required', 'string', 'in:add,update,remove'],
            $key.'.*.component_item_id' => ['required', 'uuid', 'distinct', $this->tenantOwns('mfg_items')],
            $key.'.*.quantity_per' => ['required_if:'.$key.'.*.action,add', 'nullable', ...self::PositiveQuantity],
            $key.'.*.scrap_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999'],
            $key.'.*.yield_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            $key.'.*.is_phantom' => ['nullable', 'boolean'],
            $key.'.*.issue_method' => ['nullable', 'string', 'in:manual,backflush'],
            $key.'.*.operation_sequence' => ['nullable', 'integer', 'min:1', 'max:99999'],
        ];
    }
}

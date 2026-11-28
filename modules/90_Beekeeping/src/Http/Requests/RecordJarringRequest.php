<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\Country;

final class RecordJarringRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jarred_on' => ['required', 'date', 'before_or_equal:today'],
            'jar_format_g' => ['required', 'integer', Rule::in((array) config('beekeeping.jar_formats_g')), 'min:1'],
            'jars_count' => ['required', 'integer', 'between:1,100000'],
            'origin_country' => ['nullable', Rule::enum(Country::class)],
            'warehouse_id' => ['nullable', 'uuid', $this->existsInTenant('warehouses')],
            'jar_product_id' => ['nullable', 'uuid', $this->existsInTenant('products'), 'required_with:warehouse_id'],
            'cap_product_id' => ['nullable', 'uuid', $this->existsInTenant('products')],
            'label_product_id' => ['nullable', 'uuid', $this->existsInTenant('products')],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Distribution\Domain\Enums\BucketType;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * Parameters of a DRP run.
 */
final class RunDrpRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bucket_type' => ['required', 'string', Rule::in(BucketType::values())],
            'horizon' => ['required', 'integer', 'min:1', 'max:60'],
            'warehouse_ids' => ['sometimes', 'array', 'max:200'],
            'warehouse_ids.*' => ['uuid', $this->tenantExists('warehouses')],
            'stock_item_ids' => ['sometimes', 'array', 'max:500'],
            'stock_item_ids.*' => ['uuid', $this->tenantExists('stock_items')],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Distribution\Domain\Enums\ChannelType;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * A sales channel.
 */
final class SaveSalesChannelRequest extends FormRequest
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
        $ignore = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', $this->tenantUnique('distribution_sales_channels', 'code', is_string($ignore) ? $ignore : null)],
            'name' => ['required', 'string', 'max:160'],
            'channel_type' => ['required', 'string', Rule::in(ChannelType::values())],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

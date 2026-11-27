<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\OrderStatus;

final class OrderIndexRequest extends FormRequest
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
        return [
            'direction' => ['nullable', Rule::enum(Direction::class)],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'partner_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

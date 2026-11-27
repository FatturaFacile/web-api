<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontracting\Domain\Enums\DeadlineStatus;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\LedgerMovementType;

final class RegisterRequest extends FormRequest
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
            'partner_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'order_id' => ['nullable', 'uuid'],
            'movement_type' => ['nullable', Rule::enum(LedgerMovementType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'deadline_status' => ['nullable', Rule::enum(DeadlineStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

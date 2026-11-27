<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontracting\Domain\Enums\DeadlineStatus;
use Modules\Subcontracting\Domain\Enums\Direction;

final class DashboardRequest extends FormRequest
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
            'lot_id' => ['nullable', 'uuid'],
            'deadline_status' => ['nullable', Rule::enum(DeadlineStatus::class)],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ShiftPlanning\Domain\Enums\PeriodType;
use Modules\ShiftPlanning\Domain\Enums\SwapStatus;

/**
 * The filters of the read endpoints: the matrix grouping, the date window of the agenda and the swap status.
 */
final class ShiftQueryRequest extends FormRequest
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
            'group_by' => ['nullable', Rule::in(['employee', 'role'])],
            'period_type' => ['nullable', Rule::enum(PeriodType::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(SwapStatus::class)],
        ];
    }
}

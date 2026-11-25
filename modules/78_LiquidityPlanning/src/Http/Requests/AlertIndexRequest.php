<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\LiquidityPlanning\Models\LiquidityAlert;

/**
 * Filter of the alert history.
 */
final class AlertIndexRequest extends FormRequest
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
        return ['status' => ['nullable', Rule::in([LiquidityAlert::Open, LiquidityAlert::Acknowledged, LiquidityAlert::Resolved])]];
    }
}

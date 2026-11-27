<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontracting\Domain\Enums\CustomsProcessingRegime;

final class CrossBorderRequest extends FormRequest
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
            'customs_regime' => ['nullable', Rule::enum(CustomsProcessingRegime::class)],
        ];
    }
}

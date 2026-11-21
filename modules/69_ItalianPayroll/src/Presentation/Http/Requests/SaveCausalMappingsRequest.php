<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ItalianPayroll\Domain\Enums\CausalCode;
use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;

final class SaveCausalMappingsRequest extends FormRequest
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
            'consultant_format' => ['required', Rule::enum(ConsultantFormat::class)],
            'mappings' => ['required', 'array', 'min:1'],
            'mappings.*.causal_code' => ['required', Rule::enum(CausalCode::class), 'distinct'],
            'mappings.*.external_code' => ['required', 'string', 'max:24'],
        ];
    }
}

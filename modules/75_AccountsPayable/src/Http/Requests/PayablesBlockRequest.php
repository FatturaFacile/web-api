<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Places an item in dispute or under an administrative block (`kind`), with the reason kept in its history.
 */
final class PayablesBlockRequest extends FormRequest
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
            'kind' => ['required', Rule::in(['dispute', 'block'])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}

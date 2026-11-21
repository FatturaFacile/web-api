<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ItalianPayroll\Domain\Enums\GlRole;

final class SaveGlAccountsRequest extends FormRequest
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
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*.role' => ['required', Rule::enum(GlRole::class), 'distinct'],
            'accounts.*.account_id' => ['required', 'uuid'],
        ];
    }
}

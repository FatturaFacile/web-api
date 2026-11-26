<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Models\EmailBlacklist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmailBlacklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:190'],
            'reason' => ['required', 'string', Rule::in(EmailBlacklist::REASONS)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

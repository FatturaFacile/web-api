<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class VerifyAuditIntegrityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_sequence' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'to_sequence' => ['sometimes', 'nullable', 'integer', 'min:1', 'gte:from_sequence'],
        ];
    }
}

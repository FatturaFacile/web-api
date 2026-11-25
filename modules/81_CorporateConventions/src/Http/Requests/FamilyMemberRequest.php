<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CorporateConventions\Domain\Enums\Relationship;

final class FamilyMemberRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            'relationship' => ['required', Rule::in([Relationship::Spouse->value, Relationship::Child->value, Relationship::Parent->value])],
        ];
    }
}

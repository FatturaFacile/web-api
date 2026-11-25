<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CorporateConventions\Domain\Enums\ConventionStatus;

final class TransitionRequest extends FormRequest
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
        return ['status' => ['required', Rule::in([ConventionStatus::Active->value, ConventionStatus::Suspended->value, ConventionStatus::Expired->value])]];
    }
}

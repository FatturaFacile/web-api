<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Intrastat\Support\PeriodicityRules;

final class PeriodicityRequest extends FormRequest
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
            'year' => ['required', 'integer', 'between:'.PeriodicityRules::FirstSupportedYear.',2099'],
            'quarter' => ['required', 'integer', 'between:1,4'],
        ];
    }
}

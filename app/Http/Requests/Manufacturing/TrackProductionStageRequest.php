<?php

declare(strict_types=1);

namespace App\Http\Requests\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

class TrackProductionStageRequest extends FormRequest
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
            'actual_hours' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:pending,running,done'],
        ];
    }
}

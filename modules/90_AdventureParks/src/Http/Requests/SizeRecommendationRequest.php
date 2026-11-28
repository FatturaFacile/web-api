<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Harness size for a height and a weight (public: the waiver form asks while the visitor types).
 */
final class SizeRecommendationRequest extends FormRequest
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
            'height_cm' => ['required', 'numeric', 'between:40,250'],
            'weight_kg' => ['required', 'numeric', 'between:5,300'],
        ];
    }
}

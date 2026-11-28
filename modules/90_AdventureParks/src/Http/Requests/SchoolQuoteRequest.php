<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Models\SchoolBooking;

/**
 * Live calculator of the school quote (nothing is saved).
 */
final class SchoolQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', SchoolBooking::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'paying_students' => ['required', 'integer', 'min:0', 'max:2000'],
            'disabled_students' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'accompanying_teachers' => ['nullable', 'integer', 'min:0', 'max:500'],
            'workshop_ids' => ['nullable', 'array', 'max:20'],
            'workshop_ids.*' => ['uuid'],
        ];
    }
}

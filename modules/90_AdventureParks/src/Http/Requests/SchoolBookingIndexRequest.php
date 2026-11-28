<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AdventureParks\Domain\Enums\SchoolBookingStatus;
use Modules\AdventureParks\Models\SchoolBooking;

final class SchoolBookingIndexRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(SchoolBookingStatus::class)],
        ];
    }
}

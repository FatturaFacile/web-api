<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Models\Ticket;

/**
 * Day to look at (availability, dashboard, daily opening check); today when omitted.
 */
final class DateQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Ticket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}

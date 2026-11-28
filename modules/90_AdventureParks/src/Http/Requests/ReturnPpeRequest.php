<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Models\Ticket;

final class ReturnPpeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('operate', Ticket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'barcodes' => ['nullable', 'array', 'max:200'],
            'barcodes.*' => ['string', 'max:60'],
            'usage_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Models\Ticket;

final class AssignPpeRequest extends FormRequest
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
            'participant_id' => ['required', 'uuid'],
            'barcode' => ['required', 'string', 'max:60'],
        ];
    }
}

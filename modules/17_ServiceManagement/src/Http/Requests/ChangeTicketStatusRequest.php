<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Enums\TicketStatus;

final class ChangeTicketStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(TicketStatus::settableValues())],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PortalHelpdeskStoreTicketRequest extends FormRequest
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
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
        ];
    }
}

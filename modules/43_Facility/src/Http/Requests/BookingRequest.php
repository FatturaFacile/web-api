<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;

/**
 * A booking of a space. user_id books on behalf of another member (facility managers only; the default is the caller).
 */
final class BookingRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'space_id' => ['required', 'uuid', $this->tenantOwns('fac_spaces')],
            'user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'title' => ['nullable', 'string', 'max:150'],
            'attendees' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateDelegationRequest extends FormRequest
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
            'delegate_user_id' => ['required', 'uuid', 'not_in:'.$this->user()?->id, TenantRule::member()],
            'scope' => ['required', 'string', 'in:all,procurement,expenses,invoicing,hr,custom'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at', 'after:now'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

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
     * A plain date covers the whole day.
     */
    protected function prepareForValidation(): void
    {
        // A plain date (Y-m-d) covers the whole day: from 00:00 of the first day to 23:59:59 of the last.
        $merge = [];

        if (is_string($this->input('starts_at')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->input('starts_at')) === 1) {
            $merge['starts_at'] = $this->input('starts_at').' 00:00:00';
        }

        if (is_string($this->input('ends_at')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->input('ends_at')) === 1) {
            $merge['ends_at'] = $this->input('ends_at').' 23:59:59';
        }

        $this->merge($merge);
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
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at', 'after:now'],
            'document_types' => ['nullable', 'array', 'max:20'],
            'document_types.*' => ['string', 'max:100'],
            'amount_cap' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

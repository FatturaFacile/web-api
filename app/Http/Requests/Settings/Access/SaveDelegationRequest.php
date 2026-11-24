<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings\Access;

use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A temporary delegation: the delegate acts with the access of the delegator (the signed-in user unless an
 * administrator names another one) between two dates, for every module or only the listed ones.
 */
class SaveDelegationRequest extends FormRequest
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
            'delegate_id' => ['required', 'uuid'],
            'delegator_id' => ['sometimes', 'nullable', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'modules' => ['sometimes', 'nullable', 'array'],
            'modules.*' => ['string', Rule::in(app(TenantMembershipService::class)->modules())],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('delegator_id') && $this->input('delegator_id') === $this->input('delegate_id')) {
                    $validator->errors()->add('delegate_id', __('access.errors.delegate_is_delegator'));
                }
            },
        ];
    }
}

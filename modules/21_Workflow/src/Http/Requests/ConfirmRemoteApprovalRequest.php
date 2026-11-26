<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The confirmation of a one-click approval link. The link itself never decides: the approver opens the confirmation page,
 * reads what is asked and confirms. Above the amount threshold they also prove who they are again (password, or a
 * verification code when they use two-factor authentication).
 */
final class ConfirmRemoteApprovalRequest extends FormRequest
{
    /**
     * The token in the URL is the credential; nothing else to authorise here.
     */
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
            'action' => ['required', 'string', 'in:approve,reject'],
            'confirm' => ['required', 'accepted'],
            'comment' => ['nullable', 'string', 'max:1000', 'required_if:action,reject'],
            'password' => ['nullable', 'string', 'max:255'],
            'otp' => ['nullable', 'string', 'max:32'],
        ];
    }
}

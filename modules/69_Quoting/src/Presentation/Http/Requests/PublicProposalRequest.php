<?php

declare(strict_types=1);

namespace Modules\Quoting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One request class for every public portal action: the rules depend on the controller action of the route.
 */
final class PublicProposalRequest extends FormRequest
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
        $method = 'rulesFor'.ucfirst((string) $this->route()?->getActionMethod());

        return method_exists($this, $method) ? $this->{$method}() : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForVerifyPin(): array
    {
        return ['pin' => ['required', 'string', 'max:12']];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForSelections(): array
    {
        return ['selections' => ['required', 'array', 'max:500'], 'selections.*' => ['boolean']];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForInitiateSignature(): array
    {
        return [
            'signer_name' => ['required', 'string', 'max:190'],
            'signer_role' => ['nullable', 'string', 'max:120'],
            'signer_email' => ['nullable', 'email', 'max:190'],
            'signer_phone' => ['nullable', 'string', 'max:40'],
            'otp_channel' => ['sometimes', 'in:email,sms'],
            'consent' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForCompleteSignature(): array
    {
        return ['envelope_id' => ['required', 'uuid'], 'otp' => ['required', 'digits:6']];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForReject(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:2000']];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForComment(): array
    {
        return ['body' => ['required', 'string', 'max:5000'], 'line_id' => ['nullable', 'uuid'], 'author_name' => ['nullable', 'string', 'max:190']];
    }
}

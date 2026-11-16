<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

/**
 * A signer confirms with the personal token.
 */
final class SignContractRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:200']];
    }
}

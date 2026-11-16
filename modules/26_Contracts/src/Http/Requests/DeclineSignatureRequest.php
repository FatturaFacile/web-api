<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

/**
 * A signer declines to sign.
 */
final class DeclineSignatureRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:200'], 'reason' => ['required', 'string', 'max:500']];
    }
}

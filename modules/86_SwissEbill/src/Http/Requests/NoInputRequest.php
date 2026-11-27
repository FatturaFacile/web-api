<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Actions that take no body (test the connection, rotate the secret, cancel, retry): the authorization sits in the policy.
 */
final class NoInputRequest extends FormRequest
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
        return [];
    }
}

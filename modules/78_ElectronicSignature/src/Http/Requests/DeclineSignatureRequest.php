<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A recipient declines to sign; the reason is mandatory.
 */
final class DeclineSignatureRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}

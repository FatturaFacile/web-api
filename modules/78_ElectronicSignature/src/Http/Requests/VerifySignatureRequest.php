<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public verification: the verification code, the SHA-256 of the file, or the file itself.
 */
final class VerifySignatureRequest extends FormRequest
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
            'code' => ['nullable', 'string', 'max:40', 'required_without_all:sha256,file'],
            'sha256' => ['nullable', 'string', 'size:64', 'regex:/^[A-Fa-f0-9]{64}$/', 'required_without_all:code,file'],
            'file' => ['nullable', 'file', 'max:25600', 'required_without_all:code,sha256'],
        ];
    }
}

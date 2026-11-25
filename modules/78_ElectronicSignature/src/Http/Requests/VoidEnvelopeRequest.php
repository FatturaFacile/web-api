<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class VoidEnvelopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('signatures.send');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}

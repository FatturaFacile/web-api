<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class AuditPhotoRequest extends FormRequest
{
    use ScopesToTenant;

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
            'photo' => ['required', 'file', 'image', 'max:'.config('franchise_network.audit.photo_max_kb')],
            'criterion_code' => ['nullable', 'string', 'max:60'],
            'caption' => ['nullable', 'string', 'max:200'],
        ];
    }
}

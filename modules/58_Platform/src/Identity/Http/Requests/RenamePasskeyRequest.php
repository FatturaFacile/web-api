<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class RenamePasskeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
        ];
    }
}

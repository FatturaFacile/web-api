<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Documents\Http\Requests\Concerns\ValidatesDocumentFields;

final class StoreFolderRequest extends FormRequest
{
    use ValidatesDocumentFields;

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
            'name' => ['required', 'string', 'max:190'],
            'parent_id' => ['nullable', 'uuid', $this->existsInTenant('dms_folders')],
            'access_roles' => ['nullable', 'array'],
            'access_roles.*' => ['string', 'max:60'],
        ];
    }
}

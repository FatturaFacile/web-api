<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Documents\Http\Requests\Concerns\ValidatesDocumentFields;

/**
 * Validates the input of the store folder operation (authorization is decided by module.access).
 */
final class StoreFolderRequest extends FormRequest
{
    use ValidatesDocumentFields;

    /**
     * Access is decided by the module.access middleware.
     */
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

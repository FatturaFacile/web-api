<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Associations\Services\CatalogRegistry;

final class SaveCatalogEntryRequest extends FormRequest
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
        $id = $this->route('id');

        return app(CatalogRegistry::class)->rules((string) $this->route('catalog'), is_string($id) ? $id : null);
    }
}

<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ProductCatalog\Domain\Enums\ImportMode;

/**
 * An exchange file (.csv or .xlsx) and the way to import it.
 */
final class ImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,xlsx'],
            'mode' => ['sometimes', Rule::enum(ImportMode::class)],
        ];
    }
}

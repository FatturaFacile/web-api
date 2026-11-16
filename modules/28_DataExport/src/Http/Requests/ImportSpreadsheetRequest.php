<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Requests;

use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataExport\Services\SpreadsheetImportMapper;

/**
 * Upload of a CSV or XLSX file for the import mapper. The `mapping` (target field to file header) is needed by the
 * import itself, the preview only needs the file and the dataset.
 */
final class ImportSpreadsheetRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and, for the creation of records, by the policy of the dataset.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Contact::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $import = $this->routeIs('exports.imports.store');

        return [
            'dataset' => ['required', 'string', Rule::in(SpreadsheetImportMapper::datasets())],
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,txt,xlsx'],
            'mapping' => [$import ? 'required' : 'prohibited', 'array', 'min:1'],
            'mapping.*' => ['required', 'string', 'max:255'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}

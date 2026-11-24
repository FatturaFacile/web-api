<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Merge of a duplicate contact into a surviving one. Both must be live contacts owned by the active tenant.
 */
final class MergeContactsRequest extends FormRequest
{
    use TenantScopedRules;

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
            'survivor_id' => ['required', 'uuid', $this->tenantOwns('contacts')->whereNull('deleted_at')],
            'merged_id' => ['required', 'uuid', 'different:survivor_id', $this->tenantOwns('contacts')->whereNull('deleted_at')],
            'fill_missing' => ['nullable', 'boolean'],
        ];
    }
}

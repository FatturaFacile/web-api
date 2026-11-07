<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Fields shared by the creation and the update of a document.
 */
trait ValidatesDocumentFields
{
    /**
     * @return array<string, mixed>
     */
    protected function documentFieldRules(): array
    {
        return [
            'folder_id' => ['nullable', 'uuid', $this->existsInTenant('dms_folders')],
            'retention_policy_id' => ['nullable', 'uuid', $this->existsInTenant('dms_retention_policies')],
            'description' => ['nullable', 'string', 'max:5000'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'access_roles' => ['nullable', 'array'],
            'access_roles.*' => ['string', 'max:60'],
            'metadata' => ['nullable', 'array'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['uuid', $this->existsInTenant('dms_tags')],
        ];
    }

    protected function existsInTenant(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, 'id')
            ->whereNull('deleted_at')
            ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}

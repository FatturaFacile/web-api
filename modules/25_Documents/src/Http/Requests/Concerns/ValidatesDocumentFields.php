<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsRetentionPolicy;
use Modules\Documents\Services\DocumentRetention;

/**
 * Fields shared by the creation and the update of a document. A document under statutory retention cannot get a delete
 * policy shorter than its statutory period, whether the policy or the document type is the field that changes.
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

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['retention_policy_id', 'document_type'])) {
                return;
            }

            $document = is_string($this->route('id')) ? DmsDocument::query()->find($this->route('id')) : null;
            $documentType = $this->has('document_type') ? $this->input('document_type') : $document?->document_type;
            $policyId = $this->has('retention_policy_id') ? $this->input('retention_policy_id') : $document?->retention_policy_id;
            $policy = is_string($policyId) ? DmsRetentionPolicy::query()->find($policyId) : null;

            $conflict = $policy === null ? null : DocumentRetention::statutoryPurgeConflict($documentType, (string) $policy->action, (int) $policy->retention_months);

            if ($conflict !== null) {
                $validator->errors()->add('retention_policy_id', $conflict);
            }
        }];
    }

    protected function existsInTenant(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, 'id')
            ->whereNull('deleted_at')
            ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}

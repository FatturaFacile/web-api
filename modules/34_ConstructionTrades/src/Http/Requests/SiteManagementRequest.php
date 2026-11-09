<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ConstructionTrades\Models\SiteDocument;
use Modules\ConstructionTrades\Models\Subcontractor;

/**
 * Subcontractors, subcontracts and their certifications, the site diary and the site documents. Each route names the
 * payload it validates.
 */
final class SiteManagementRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
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
        $tenantId = app(TenantContext::class)->getTenantId();
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return match (str_replace('api.v1.construction-trades.', '', (string) $this->route()?->getName())) {
            'subcontractors.store', 'subcontractors.update' => [
                'name' => [$presence, 'string', 'max:190'],
                'contact_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('contacts')],
                'tax_id' => ['sometimes', 'nullable', 'string', 'max:40'],
                'trade' => ['sometimes', 'nullable', 'string', 'max:100'],
                'status' => ['sometimes', Rule::in(Subcontractor::Statuses)],
                'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'sites.subcontracts.store' => [
                'subcontractor_id' => ['required', 'uuid', $this->tenantOwns('ct_subcontractors')],
                'bid_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('ct_bids')],
                'description' => ['required', 'string', 'max:500'],
                'contract_amount' => ['required', 'decimal:0,2', 'gt:0'],
                'starts_on' => ['sometimes', 'nullable', 'date'],
                'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            ],
            'subcontracts.certifications.store' => [
                'certified_on' => ['required', 'date'],
                'amount' => ['required', 'decimal:0,2', 'gt:0'],
                'description' => ['required', 'string', 'max:500'],
            ],
            'subcontracts.status' => ['status' => ['required', 'string', Rule::in(['completed', 'cancelled'])]],
            'sites.diary.index', 'sites.documents.index' => [],
            'sites.diary.store', 'diary.update' => [
                'entry_date' => $this->isMethod('POST') ? ['required', 'date'] : ['prohibited'],
                'weather' => ['sometimes', 'nullable', 'string', 'max:100'],
                'workers_present' => [$presence, 'integer', 'min:0', 'max:5000'],
                'work_performed' => [$presence, 'string', 'max:5000'],
                'incidents' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ],
            'sites.documents.store' => [
                'kind' => ['required', 'string', Rule::in(SiteDocument::Kinds)],
                'title' => ['required', 'string', 'max:255'],
                'subcontractor_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('ct_subcontractors')],
                'dms_document_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('dms_documents', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
                'issued_on' => ['sometimes', 'nullable', 'date'],
                'expires_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_on'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'site-documents.expiring' => ['within_days' => ['required', 'integer', 'min:0', 'max:3650']],
            default => [],
        };
    }
}

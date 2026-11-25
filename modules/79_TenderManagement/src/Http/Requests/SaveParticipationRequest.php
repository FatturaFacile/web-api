<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TenderManagement\Domain\Enums\ParticipationMode;
use Modules\TenderManagement\Domain\Support\SoaClassification;

/**
 * Creation (with the tender id) and edit of a participation dossier.
 */
final class SaveParticipationRequest extends FormRequest
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
        $tenantId = (string) (app(TenantContext::class)->getTenantId() ?? '');

        return [
            'tender_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid', Rule::exists('tnd_notices', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'participation_mode' => ['sometimes', Rule::in(ParticipationMode::values())],
            'rti_quota_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'partner_companies' => ['nullable', 'array', 'max:20'],
            'partner_companies.*.name' => ['required_with:partner_companies', 'string', 'max:200'],
            'partner_companies.*.vat_number' => ['nullable', 'string', 'max:30'],
            'partner_companies.*.role' => ['required_with:partner_companies', Rule::in(['mandataria', 'mandante', 'ausiliaria'])],
            'partner_companies.*.quota_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'partner_companies.*.soa_categories' => ['nullable', 'array'],
            'partner_companies.*.soa_categories.*' => ['string', Rule::in(SoaClassification::categories())],
            'notes' => ['nullable', 'string', 'max:5000'],
            'provisional_bid_bond_id' => ['nullable', 'uuid'],
            'anac_contribution_receipt_id' => ['nullable', 'string', 'max:80'],
            'technical_envelope_score_target' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}

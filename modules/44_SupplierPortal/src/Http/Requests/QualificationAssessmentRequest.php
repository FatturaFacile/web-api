<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Opens a qualification assessment (POST) or records scores on a draft one (PUT).
 */
final class QualificationAssessmentRequest extends FormRequest
{
    use TenantScopedRules;

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
        $creating = $this->isMethod('POST');

        return [
            'supplier_id' => [$creating ? 'required' : 'prohibited', 'uuid', $this->tenantOwns('suppliers')],
            'onboarding_id' => [$creating ? 'nullable' : 'prohibited', 'uuid', $this->tenantOwns('sp_onboardings')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scores' => [$creating ? 'nullable' : 'required', 'array', 'max:100'],
            'scores.*.criterion_id' => ['required', 'uuid', $this->tenantOwns('sp_qualification_criteria')],
            'scores.*.score' => ['required', 'numeric', 'min:0', 'max:100'],
            'scores.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

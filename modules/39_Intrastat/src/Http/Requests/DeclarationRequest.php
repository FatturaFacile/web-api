<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Enums\Periodicity;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Services\DeclarationGenerator;
use Modules\Intrastat\Support\IntrastatCodes;
use Modules\Intrastat\Support\PeriodicityRules;

/**
 * The frontespizio of a list. The flow is chosen when the list is created and never changes.
 */
class DeclarationRequest extends FormRequest
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
        $isCreation = $this->isMethod('post');

        return [
            'flow' => [$isCreation ? 'required' : 'prohibited', Rule::enum(IntrastatFlow::class)],
            'year' => [$isCreation ? 'required' : 'sometimes', 'integer', 'between:'.PeriodicityRules::FirstSupportedYear.',2099'],
            'periodicity' => [$isCreation ? 'required' : 'sometimes', Rule::enum(Periodicity::class)],
            'period' => [$isCreation ? 'required' : 'sometimes', 'integer', 'between:1,12'],
            'reference_number' => [
                'nullable', 'integer', 'between:1,999999',
                Rule::unique('intrastat_declarations', 'reference_number')
                    ->where('tenant_id', app(TenantContext::class)->getTenantId())
                    ->ignore($this->route('id')),
            ],
            'content_code' => ['sometimes', 'integer', Rule::in(array_keys(IntrastatCodes::ContentCodes))],
            'special_case_code' => ['sometimes', 'integer', Rule::in(array_keys(IntrastatCodes::SpecialCaseCodes))],
            'obligated_vat_number' => ['nullable', 'regex:/^\d{11}$/'],
            'delegate_vat_number' => ['nullable', 'regex:/^\d{11}$/'],
            'includes_statistical_data' => ['sometimes', 'boolean'],
            'has_annual_flows_above_20_million' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $flow = IntrastatFlow::tryFrom((string) $this->input('flow', $this->currentFlow()));
                $periodicity = Periodicity::tryFrom((string) $this->input('periodicity', $this->currentPeriodicity()));

                if ($flow !== null && $periodicity !== null && ! DeclarationGenerator::allowsPeriodicity($flow, $periodicity)) {
                    $validator->errors()->add('periodicity', 'The quarterly INTRA-2 lists are abolished from 2022: purchases are filed monthly.');
                }

                if ($periodicity !== null && $this->filled('period') && (int) $this->input('period') > $periodicity->lastPeriod()) {
                    $validator->errors()->add('period', 'A quarterly list has a period from 1 to 4.');
                }
            },
        ];
    }

    private function currentFlow(): ?string
    {
        return $this->current()?->flow->value;
    }

    private function currentPeriodicity(): ?string
    {
        return $this->current()?->periodicity->value;
    }

    private function current(): ?IntrastatDeclaration
    {
        $id = $this->route('id');

        return is_string($id) ? IntrastatDeclaration::query()->find($id) : null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * A configurable model, on creation (POST) or change (PUT).
 */
final class CpqModelRequest extends CpqFormRequest
{
    /**
     * Codes are stored in upper case: validate the uniqueness on what will be stored.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : null,
            'part_number_prefix' => is_string($this->input('part_number_prefix')) ? strtoupper(trim($this->input('part_number_prefix'))) : null,
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->presence();

        return [
            'code' => [$presence, 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('cpq_models', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($this->route('id'))],
            ...$this->i18n('name', true),
            ...$this->i18n('description', false, 5000),
            'currency' => ['sometimes', 'string', 'size:3'],
            'base_price' => ['sometimes', 'numeric', 'min:0', 'max:99999999999'],
            'base_cost' => ['sometimes', 'numeric', 'min:0', 'max:99999999999'],
            'base_lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'target_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'max_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'part_number_prefix' => [$presence, 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'steps' => ['sometimes', 'nullable', 'array', 'max:30'],
            'steps.*.code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', 'distinct'],
            'steps.*.title' => ['required', 'array'],
            'steps.*.description' => ['nullable', 'array'],
            'visual_base' => ['sometimes', 'nullable', 'array'],
            'proposal_texts' => ['sometimes', 'nullable', 'array'],
            'proposal_texts.validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'proposal_texts.title' => ['nullable', 'array'],
            'proposal_texts.intro' => ['nullable', 'array'],
            'proposal_texts.warranty' => ['nullable', 'array'],
            'proposal_texts.terms' => ['nullable', 'array'],
            'proposal_texts.delivery' => ['nullable', 'array'],
            'proposal_texts.installation' => ['nullable', 'array'],
            'proposal_texts.dimensional_notes' => ['nullable', 'array', 'max:30'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper((string) $data['currency']);
        }

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ItalianVat\Services\ThirdPartyGoodsService;

/**
 * A movement of goods on account of a third party.
 */
final class GoodsMovementRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'section_id' => ['nullable', 'uuid'],
            'movement_date' => ['required', 'date'],
            'movement_type' => ['required', Rule::in(ThirdPartyGoodsService::TYPES)],
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'description' => ['required', 'string', 'max:200'],
            'unit' => ['nullable', 'string', 'max:10'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'document_number' => ['nullable', 'string', 'max:50'],
            'document_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

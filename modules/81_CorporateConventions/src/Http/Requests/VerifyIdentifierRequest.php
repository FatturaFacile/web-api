<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A pass, card number, matricola or e-mail presented at the till or the back office; with a name the holder of a listed
 * card or hash is enrolled when no pass exists yet.
 */
final class VerifyIdentifierRequest extends FormRequest
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
        return [
            'identifier' => ['required', 'string', 'max:190'],
            'convention_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('sales_partner_conventions', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
        ];
    }
}

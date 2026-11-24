<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Support\LocalizedLabel;

/**
 * Creation or update of a loss reason of the catalog used by the mandatory loss questionnaire.
 */
final class SaveLossReasonRequest extends FormRequest
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
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => $creating
                ? ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('crm_loss_reasons', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())]
                : ['prohibited'],
            'name' => [$required, 'string', 'max:150'],
            'translations' => ['nullable', 'array'],
            'translations.*' => ['nullable', 'string', 'max:150'],
            'requires_competitor' => ['nullable', 'boolean'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        if (isset($data['translations']) && is_array($data['translations'])) {
            $data['translations'] = array_intersect_key($data['translations'], array_flip(LocalizedLabel::LOCALES));
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }
}

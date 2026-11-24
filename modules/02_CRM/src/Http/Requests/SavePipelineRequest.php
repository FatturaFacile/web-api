<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Support\LocalizedLabel;

/**
 * Creation or update of a sales pipeline (business line / market). The code is unique per tenant and immutable.
 */
final class SavePipelineRequest extends FormRequest
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
                ? ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('crm_pipelines', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())]
                : ['prohibited'],
            'name' => [$required, 'string', 'max:150'],
            'business_line' => ['nullable', 'string', 'max:100'],
            'market' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'translations' => ['nullable', 'array'],
            'translations.*' => ['nullable', 'string', 'max:150'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'install_standard_stages' => ['nullable', 'boolean'],
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

<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;

final class SaveGarmentRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'epc_uid_rfid_hex' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'string', 'max:80'],
            'thermal_barcode' => ['nullable', 'string', 'max:60'],
            'article_type' => [$required, Rule::in(ArticleType::values())],
            'product_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:160'],
            'assigned_customer_id' => ['nullable', 'uuid'],
            'max_certified_wash_cycles_limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'ppe_certification_standard' => ['nullable', 'string', 'max:60'],
            'ppe_category' => ['nullable', 'integer', 'between:1,3'],
        ];
    }
}

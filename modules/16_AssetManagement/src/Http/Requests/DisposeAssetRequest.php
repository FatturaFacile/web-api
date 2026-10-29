<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DisposeAssetRequest extends FormRequest
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
            'disposal_type' => ['required', 'string', 'in:sale,scrap,retirement,donation,theft_loss'],
            'proceeds_amount' => ['nullable', 'numeric', 'min:0'],
            'disposal_costs' => ['nullable', 'numeric', 'min:0'],
            'disposal_date' => ['nullable', 'date'],
            'buyer_contact_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}

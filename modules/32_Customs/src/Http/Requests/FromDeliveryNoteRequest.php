<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Customs\Enums\DeclarationType;

final class FromDeliveryNoteRequest extends FormRequest
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
            'delivery_note_id' => ['required', 'uuid', CustomsRules::exists('delivery_notes')],
            'declaration_type' => ['required', Rule::enum(DeclarationType::class)],
        ];
    }
}

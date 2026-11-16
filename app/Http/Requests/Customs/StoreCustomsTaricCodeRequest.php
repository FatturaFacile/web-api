<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use App\Enums\TaricCodeLevel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomsTaricCodeRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$presence, 'string', 'regex:/^\d{6}(\d{2}(\d{2})?)?$/', Rule::unique('customs_taric_codes', 'code')->ignore($this->route('id'))],
            'level' => ['sometimes', Rule::enum(TaricCodeLevel::class)],
            'chapter' => ['sometimes', 'string', 'size:2', 'regex:/^\d{2}$/'],
            'description' => [$presence, 'array'],
            'description.*' => ['nullable', 'string'],
            'supplementary_unit' => ['nullable', 'string', 'max:255'],
            'duty_rate_notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Level and chapter are derived from the code, and must agree with it when supplied.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $code = $this->input('code');
                if (! is_string($code)) {
                    return;
                }

                $level = TaricCodeLevel::fromCode($code);
                if ($level !== null && $this->filled('level') && $this->input('level') !== $level->value) {
                    $validator->errors()->add('level', 'The level does not match the number of digits of the code.');
                }
                if ($this->filled('chapter') && $this->input('chapter') !== substr($code, 0, 2)) {
                    $validator->errors()->add('chapter', 'The chapter must match the first two digits of the code.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedWithDerivedFields(): array
    {
        $data = $this->validated();
        if (isset($data['code'])) {
            $data['level'] = TaricCodeLevel::fromCode($data['code'])?->value;
            $data['chapter'] = substr($data['code'], 0, 2);
        }

        return $data;
    }
}

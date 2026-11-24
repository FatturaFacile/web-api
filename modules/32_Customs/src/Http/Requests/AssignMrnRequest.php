<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Support\Mrn;

final class AssignMrnRequest extends FormRequest
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
            'mrn' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! Mrn::isValid($value)) {
                    $fail('The MRN must have 18 to 21 letters and digits (18 for a Union MRN: year, country, 14 characters).');

                    return;
                }

                $taken = CustomsDeclaration::query()->where('mrn', Mrn::normalize($value))->whereKeyNot((string) $this->route('id'))->exists();

                if ($taken) {
                    $fail('Another declaration already holds this MRN.');
                }
            }],
        ];
    }
}

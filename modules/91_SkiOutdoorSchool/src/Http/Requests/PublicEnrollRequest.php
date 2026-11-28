<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemType;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * A tourist books a place in a weekly group course on the public portal.
 */
final class PublicEnrollRequest extends FormRequest
{
    use SkiSchoolRules;

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
            'student_name' => ['required', 'string', 'max:160'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'guardian_name' => ['nullable', 'string', 'max:160'],
            'guardian_email' => ['required', 'email', 'max:160'],
            'emergency_contact_name' => ['required', 'string', 'max:160'],
            'emergency_contact_phone' => ['required', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array', 'max:10'],
            'items.*.item_type' => ['required', Rule::in(PackageItemType::values())],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
            'items.*.days' => ['nullable', 'integer', 'min:1', 'max:14'],
        ];
    }
}

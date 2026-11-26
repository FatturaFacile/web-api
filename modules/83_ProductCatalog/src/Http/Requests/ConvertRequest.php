<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A quantity in one unit of a product, to be expressed in all its other units.
 */
final class ConvertRequest extends FormRequest
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
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'unit' => ['required', 'string', 'max:30'],
        ];
    }
}

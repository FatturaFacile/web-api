<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GridQuoteRequest extends FormRequest
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
        return ['tariff_grid_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('customs_tariff_grids')]];
    }
}

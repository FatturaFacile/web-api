<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportPriceListRequest extends FormRequest
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
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'max:20480']];
    }
}

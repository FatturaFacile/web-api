<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the CSV file of a price list import.
 */
final class ImportPriceListRequest extends FormRequest
{
    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
     */
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

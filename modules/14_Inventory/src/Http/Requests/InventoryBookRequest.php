<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query of `GET book` and body of `POST book/exports`.
 */
final class InventoryBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:1900', 'max:2999'],
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid'],
            'format' => ['sometimes', 'string', 'in:json,csv'],
            'require_closed' => ['sometimes', 'nullable'], // boolean-like: true|false|1|0
        ];
    }

    public function year(): int
    {
        return (int) $this->input('year');
    }

    public function asOf(): ?string
    {
        return $this->filled('as_of') ? $this->string('as_of')->value() : null;
    }

    public function warehouseId(): ?string
    {
        return $this->filled('warehouse_id') ? $this->string('warehouse_id')->value() : null;
    }

    public function requireClosed(): bool
    {
        return $this->boolean('require_closed');
    }

    public function wantsCsv(): bool
    {
        return $this->input('format') === 'csv';
    }
}

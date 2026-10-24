<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\BarcodeType;
use App\Services\Inventory\BarcodeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductBarcodeRequest extends FormRequest
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
            'format' => ['nullable', Rule::in([BarcodeService::FORMAT_SVG, BarcodeService::FORMAT_PNG])],
            'type' => ['nullable', Rule::enum(BarcodeType::class)],
            'scale' => ['nullable', 'integer', 'between:1,10'],
            'height' => ['nullable', 'integer', 'between:10,300'],
        ];
    }

    public function barcodeType(): BarcodeType
    {
        return BarcodeType::from((string) $this->query('type', BarcodeType::Code128->value));
    }

    public function outputFormat(): string
    {
        return (string) $this->query('format', BarcodeService::FORMAT_SVG);
    }

    public function scale(): int
    {
        return (int) $this->query('scale', 2);
    }

    public function height(): int
    {
        return (int) $this->query('height', 40);
    }
}

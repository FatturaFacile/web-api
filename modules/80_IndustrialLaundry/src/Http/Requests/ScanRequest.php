<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;

/**
 * The payload a UHF reader middleware (Impinj, Zebra, CAEN) or the scan console sends for a roll cage at a tunnel gate.
 */
final class ScanRequest extends FormRequest
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
            'gate_antenna_id' => ['required', 'string', 'max:60'],
            'roll_cage_barcode' => ['required', 'string', 'max:60'],
            'direction' => ['required', Rule::in(ScanDirection::values())],
            'reads' => ['present', 'array', 'max:5000'],
            'reads.*.epc' => ['required', 'string', 'max:80'],
            'reads.*.rssi' => ['nullable', 'numeric', 'between:-120,20'],
            'reads.*.antenna_id' => ['nullable', 'string', 'max:60'],
            'reads.*.seen_at' => ['nullable', 'date'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'roll_cage_tare_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'contact_id' => ['nullable', 'uuid'],
            'external_scan_id' => ['nullable', 'string', 'max:80'],
            'scanned_at' => ['nullable', 'date'],
            'generate_ddt' => ['sometimes', 'boolean'],
        ];
    }
}

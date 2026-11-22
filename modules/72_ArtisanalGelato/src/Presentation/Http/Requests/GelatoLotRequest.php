<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lot actions: start a lot (kind, method, parent base, consumptions), add HACCP readings, complete with the yield, and store a
 * scale reading. Each action reads only its own fields.
 */
final class GelatoLotRequest extends FormRequest
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
            'lot_code' => ['nullable', 'string', 'max:48'],
            'kind' => [$this->routeIs('gelato.lots.store') ? 'required' : 'nullable', Rule::in(['base', 'flavor'])],
            'method' => ['nullable', Rule::in(['direct', 'two_stage'])],
            'recipe_id' => ['nullable', 'uuid'],
            'parent_lot_id' => ['nullable', 'uuid'],
            'equipment' => ['nullable', 'string', 'max:64'],
            'mix_weight_grams' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'mix_volume_liters' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'consumptions' => ['nullable', 'array', 'max:100'],
            'consumptions.*.ingredient_id' => ['nullable', 'uuid'],
            'consumptions.*.source_lot_id' => ['nullable', 'uuid'],
            'consumptions.*.raw_lot_code' => ['nullable', 'string', 'max:64'],
            'consumptions.*.grams' => ['required_with:consumptions', 'numeric', 'gt:0', 'max:1000000'],
            'readings' => [$this->routeIs('gelato.lots.haccp') ? 'required' : 'nullable', 'array', 'min:1', 'max:500'],
            'readings.*.at' => ['required_with:readings', 'date'],
            'readings.*.temperature_celsius' => ['required_with:readings', 'numeric', 'min:-60', 'max:150'],
            'readings.*.phase' => ['nullable', Rule::in(['heat', 'cool', 'age', 'harden'])],
            'gelato_volume_liters' => ['nullable', 'numeric', 'gt:0', 'max:10000'],
            'yield_grams' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'yield_tubs' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'aging_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'grams' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'lot_id' => ['nullable', 'uuid'],
            'ingredient_id' => ['nullable', 'uuid'],
            'device' => ['nullable', 'string', 'max:64'],
        ];
    }
}

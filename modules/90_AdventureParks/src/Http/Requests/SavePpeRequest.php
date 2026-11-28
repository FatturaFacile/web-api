<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AdventureParks\Domain\Enums\PpeCategory;
use Modules\AdventureParks\Domain\Enums\PpeSize;
use Modules\AdventureParks\Domain\Enums\PpeStatus;
use Modules\AdventureParks\Models\PpeAsset;

final class SavePpeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', PpeAsset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $serial = Rule::unique('adv_ppe_assets', 'serial_code')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        if (! $creating) {
            $serial->ignore((string) $this->route('id'));
        }

        return [
            'serial_code' => [$required, 'string', 'max:60', $serial],
            'category' => [$required, Rule::enum(PpeCategory::class)],
            'size' => ['nullable', Rule::enum(PpeSize::class)],
            'min_height_cm' => ['nullable', 'integer', 'between:30,260'],
            'max_height_cm' => ['nullable', 'integer', 'between:30,260', 'gte:min_height_cm'],
            'min_weight_kg' => ['nullable', 'integer', 'between:1,320'],
            'max_weight_kg' => ['nullable', 'integer', 'between:1,320', 'gte:min_weight_kg'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'batch_number' => ['nullable', 'string', 'max:60'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'first_use_date' => ['nullable', 'date_format:Y-m-d'],
            'manufacturer_max_lifespan_date' => [$required, 'date_format:Y-m-d'],
            'rental_cycle_count' => ['nullable', 'integer', 'min:0'],
            'cumulative_usage_hours' => ['nullable', 'numeric', 'min:0'],
            'last_inspection_date' => ['nullable', 'date_format:Y-m-d'],
            'next_inspection_due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(PpeStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

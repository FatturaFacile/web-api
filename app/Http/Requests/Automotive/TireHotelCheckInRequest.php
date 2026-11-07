<?php

declare(strict_types=1);

namespace App\Http\Requests\Automotive;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class TireHotelCheckInRequest extends FormRequest
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
            'vehicle_id' => ['required', 'uuid', $this->ofTenant('vehicles')],
            'season' => ['required', 'in:summer,winter,all_season'],
            'brand' => ['required', 'string'],
            'model' => ['nullable', 'string'],
            'size' => ['required', 'string'],
            'dot' => ['nullable', 'string'],
            'front_left_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'front_right_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'rear_left_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'rear_right_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'warehouse_shelf_location' => ['required', 'string'],
        ];
    }

    /**
     * A row of the active company, or a generic one without company.
     */
    private function ofTenant(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}

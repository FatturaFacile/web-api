<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\TreatmentProduct;

final class RegisterTreatmentRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'apiary_id' => ['required', 'uuid', $this->existsInTenant('bee_apiaries')],
            'whole_apiary' => ['sometimes', 'boolean'],
            'hive_ids' => ['sometimes', 'array', 'max:500'],
            'hive_ids.*' => ['uuid', $this->existsInTenant('bee_hives')],
            'treatment_date' => ['required', 'date'],
            'treatment_end_date' => ['nullable', 'date', 'after_or_equal:treatment_date'],
            'product' => ['required', Rule::enum(TreatmentProduct::class)],
            'active_substance' => ['nullable', 'string', 'max:40'],
            'commercial_name' => ['nullable', 'string', 'max:120'],
            'drug_batch' => ['nullable', 'string', 'max:60'],
            'prescription_ref' => ['nullable', 'string', 'max:80'],
            'veterinarian' => ['nullable', 'string', 'max:120'],
            'dosage' => ['nullable', 'string', 'max:80'],
            'application_method' => ['nullable', 'string', 'max:40'],
            'withdrawal_days' => ['nullable', 'integer', 'between:0,365'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

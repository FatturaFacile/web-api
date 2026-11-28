<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Beekeeping\Models\VarroaTreatment;

/**
 * @mixin VarroaTreatment
 */
final class TreatmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'apiary_id' => $this->apiary_id,
            'whole_apiary' => $this->whole_apiary,
            'treatment_date' => $this->treatment_date->toDateString(),
            'treatment_end_date' => $this->treatment_end_date?->toDateString(),
            'product' => $this->product,
            'active_substance' => $this->active_substance,
            'commercial_name' => $this->commercial_name,
            'drug_batch' => $this->drug_batch,
            'prescription_ref' => $this->prescription_ref,
            'veterinarian' => $this->veterinarian,
            'dosage' => $this->dosage,
            'application_method' => $this->application_method,
            'withdrawal_days' => $this->withdrawal_days,
            'withdrawal_end_date' => $this->withdrawal_end_date->toDateString(),
            'withdrawal_active' => $this->window()->covers(CarbonImmutable::today()),
            'notes' => $this->notes,
            'hive_ids' => $this->hive_ids ?? [],
            'apiary' => $this->whenLoaded('apiary', fn (): array => ['id' => $this->apiary->id, 'code' => $this->apiary->code, 'name' => $this->apiary->name]),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\GeneticLine;
use Modules\Beekeeping\Domain\Enums\HiveOrigin;
use Modules\Beekeeping\Domain\Enums\HiveStatus;
use Modules\Beekeeping\Domain\Enums\HiveType;
use Modules\Beekeeping\Domain\Enums\QueenStatus;

final class SaveHiveRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $code = Rule::unique('bee_hives', 'code')->where('tenant_id', $this->tenantId())->whereNull('deleted_at');

        if (! $creating) {
            $code->ignore((string) $this->route('id'));
        }

        return [
            'apiary_id' => [$required, 'uuid', $this->existsInTenant('bee_apiaries')],
            'code' => [$required, 'string', 'max:40', $code],
            'hive_type' => [$required, Rule::enum(HiveType::class)],
            'origin' => ['sometimes', Rule::enum(HiveOrigin::class)],
            'status' => ['sometimes', Rule::enum(HiveStatus::class)],
            'queen_year' => ['nullable', 'integer', 'between:2000,2100'],
            'queen_clipped' => ['sometimes', 'boolean'],
            'queen_genetic_line' => ['nullable', Rule::enum(GeneticLine::class)],
            'queen_breeder' => ['nullable', 'string', 'max:120'],
            'queen_pedigree_ref' => ['nullable', 'string', 'max:120'],
            'queen_status' => ['sometimes', Rule::enum(QueenStatus::class)],
            'brood_frames' => ['sometimes', 'integer', 'between:0,40'],
            'store_frames' => ['sometimes', 'integer', 'between:0,40'],
            'supers_count' => ['sometimes', 'integer', 'between:0,12'],
            'strength_score' => ['sometimes', 'integer', 'between:1,5'],
            'temperament' => ['nullable', 'string', 'max:20'],
            'installed_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

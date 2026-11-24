<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\StatementSchemeRow;

final class StatementSchemeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'statement_type' => $this->statement_type,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'rows' => $this->rows->map(static fn (StatementSchemeRow $row): array => [
                'code' => $row->code,
                'label' => $row->label,
                'row_type' => $row->row_type,
                'sign' => (int) $row->sign,
                'account_prefixes' => $row->account_prefixes ?? [],
                'formula' => $row->formula ?? [],
            ])->all(),
        ];
    }
}

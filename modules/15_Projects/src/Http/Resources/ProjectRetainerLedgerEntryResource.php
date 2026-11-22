<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectRetainerLedgerEntry;

/**
 * A movement of the prepaid retainer of a project (Mod 081).
 *
 * @mixin ProjectRetainerLedgerEntry
 */
final class ProjectRetainerLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectRetainerLedgerEntry $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'entry_type' => $m->entry_type,
            'source_type' => $m->source_type,
            'source_id' => $m->source_id,
            'entry_date' => $m->entry_date->toDateString(),
            'hours' => (string) $m->hours,
            'amount' => (string) $m->amount,
            'balance_hours_after' => (string) $m->balance_hours_after,
            'balance_amount_after' => (string) $m->balance_amount_after,
            'note' => $m->note,
        ];
    }
}

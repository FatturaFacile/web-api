<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectRetentionLedgerEntry;

/**
 * A movement of the guarantee retention of a project (Mod 081).
 *
 * @mixin ProjectRetentionLedgerEntry
 */
final class ProjectRetentionLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectRetentionLedgerEntry $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'sal_certificate_id' => $m->sal_certificate_id,
            'entry_type' => $m->entry_type,
            'amount' => (string) $m->amount,
            'entry_date' => $m->entry_date->toDateString(),
            'reason' => $m->reason,
            'billing_event_id' => $m->billing_event_id,
        ];
    }
}

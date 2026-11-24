<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\RecurringJournal;

/**
 * @mixin RecurringJournal
 */
final class RecurringJournalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'frequency' => $this->frequency,
            'start_date' => LedgerDateFormat::day($this->start_date),
            'end_date' => LedgerDateFormat::day($this->end_date),
            'next_run_date' => LedgerDateFormat::day($this->next_run_date),
            'last_run_date' => LedgerDateFormat::day($this->last_run_date),
            'reverse_next_day' => (bool) $this->reverse_next_day,
            'status' => $this->status,
            'lines' => $this->lines,
            'runs' => $this->whenLoaded('runs', fn (): array => $this->runs->map(static fn ($run): array => [
                'run_date' => LedgerDateFormat::day($run->run_date),
                'journal_entry_id' => (string) $run->journal_entry_id,
            ])->all()),
            'created_by' => $this->created_by,
            'created_at' => LedgerDateFormat::instant($this->created_at),
        ];
    }
}

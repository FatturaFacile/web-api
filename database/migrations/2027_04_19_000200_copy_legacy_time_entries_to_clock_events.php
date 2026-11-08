<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Consolidates the legacy /time-attendance records (time_entries) onto the Human Resources clock events.
 *
 * Every live entry of a company becomes an "In" event at clock_in and, when closed, an "Out" event at clock_out, linked
 * through legacy_time_entry_id, with one creation row in the time audit history. Nothing is deleted or changed in
 * time_entries. The unique index on (legacy_time_entry_id, event_type) and the NOT EXISTS filter make a second run a
 * no-op. The legacy break minutes are kept in the note: the position of the break was never recorded and is not
 * invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('time_entries') || ! Schema::hasColumn('time_clock_events', 'legacy_time_entry_id')) {
            return;
        }

        DB::table('time_entries')
            ->whereNotNull('tenant_id')
            ->whereNull('deleted_at')
            ->whereNotExists(static fn ($query) => $query->select(DB::raw(1))->from('time_clock_events')->whereColumn('time_clock_events.legacy_time_entry_id', 'time_entries.id'))
            ->chunkById(500, function ($entries): void {
                $now = now()->utc()->format('Y-m-d H:i:s');
                $events = [];
                $audits = [];

                foreach ($entries as $entry) {
                    $note = trim('Copiata da time_entries.'.((int) $entry->break_minutes > 0 ? " Pausa registrata: {$entry->break_minutes} min." : '').($entry->notes ? ' '.$entry->notes : ''));

                    foreach (['In' => $entry->clock_in, 'Out' => $entry->clock_out] as $type => $instant) {
                        if ($instant === null) {
                            continue;
                        }

                        $id = (string) Str::uuid7();
                        $occurredAt = (string) $instant;
                        $events[] = [
                            'id' => $id, 'tenant_id' => $entry->tenant_id, 'user_id' => $entry->user_id, 'occurred_at' => $occurredAt,
                            'event_type' => $type, 'source' => 'web', 'note' => $note, 'legacy_time_entry_id' => $entry->id,
                            'created_at' => $now, 'updated_at' => $now,
                        ];
                        $audits[] = [
                            'id' => (string) Str::uuid7(), 'tenant_id' => $entry->tenant_id, 'user_id' => $entry->user_id,
                            'entity_type' => 'TimeClockEvent', 'entity_id' => $id, 'action' => 'Created', 'before_values' => null,
                            'after_values' => json_encode(['occurred_at' => $occurredAt, 'event_type' => $type, 'source' => 'web', 'note' => $note]),
                            'reason' => 'Consolidamento dei dati di /time-attendance', 'actor_id' => null, 'actor_name' => 'Migrazione di sistema',
                            'occurred_at' => $now, 'created_at' => $now,
                        ];
                    }
                }

                DB::transaction(static function () use ($events, $audits): void {
                    DB::table('time_clock_events')->insertOrIgnore($events);
                    DB::table('time_audit_entries')->insert($audits);
                });
            }, 'time_entries.id', 'id');
    }

    public function down(): void
    {
        // Non-destructive: the copied events are working time records with their own audit history.
    }
};

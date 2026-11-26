<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Jobs;

use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes a checkpoint to the write-once target (S3 Object Lock, Azure Immutable Blob or the Mod 030 preservation package,
 * configured as the `audit_ledger.anchor.disk` filesystem disk) and records where. The object is never overwritten: an
 * existing anchor with different content is an incident.
 */
final class AnchorCheckpointJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public string $checkpointId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $checkpoint = AuditCheckpoint::query()->find($this->checkpointId);

        if (! $checkpoint instanceof AuditCheckpoint || $checkpoint->anchored_at !== null) {
            return;
        }

        $diskName = (string) config('audit_ledger.anchor.disk');
        $disk = Storage::disk($diskName);
        $path = trim((string) config('audit_ledger.anchor.prefix'), '/').'/'.$checkpoint->tenant_id.'/'.$checkpoint->type.'/'.($checkpoint->period_ref ?? $checkpoint->id).'-'.$checkpoint->sequence.'.json';

        $document = (string) json_encode([
            'tenant_id' => $checkpoint->tenant_id,
            'checkpoint_id' => $checkpoint->id,
            'type' => $checkpoint->type,
            'period_ref' => $checkpoint->period_ref,
            'sequence' => $checkpoint->sequence,
            'hash' => $checkpoint->hash,
            'signature' => $checkpoint->signature,
            'key_version' => $checkpoint->key_version,
            'created_at' => $checkpoint->created_at->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($disk->exists($path)) {
            if (hash('sha256', (string) $disk->get($path)) !== hash('sha256', $document)) {
                throw new RuntimeException("The anchor {$path} already exists with different content.");
            }
        } else {
            $disk->put($path, $document);
        }

        $checkpoint->anchored_at = Carbon::now('UTC');
        $checkpoint->anchor_uri = $diskName.'://'.$path;
        $checkpoint->save();
    }
}

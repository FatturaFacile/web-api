<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Builds the tamper-evident forensic bundle: the filtered records as JSONL, in sequence order and exactly as stored
 * (payloads stay ciphertext, hashes and signatures included), closed by one manifest line that carries the record count,
 * the SHA-256 of the records and the HMAC signature of that manifest.
 */
final class AuditForensicExporter
{
    public const MAX_RECORDS = 50000;

    public function __construct(private readonly AuditChainCryptoService $crypto) {}

    /**
     * @param  Builder<AuditLog>  $query
     * @return array{content: string, sha256: string, signature: string, count: int, filename: string}
     */
    public function bundle(Builder $query): array
    {
        $lines = [];

        foreach ($query->orderBy('sequence_number')->limit(self::MAX_RECORDS)->cursor() as $row) {
            $lines[] = $this->crypto->canonicalJson(array_merge($row->only([
                'id', 'tenant_id', 'sequence_number', 'event_type', 'auditable_type', 'auditable_id', 'actor_type', 'actor_id',
                'actor_email', 'actor_role', 'ip_address', 'user_agent', 'request_id', 'route_name', 'old_values_encrypted',
                'new_values_encrypted', 'changed_fields', 'context_metadata', 'previous_hash', 'current_hash', 'hmac_signature',
            ]), ['occurred_at' => $this->crypto->normaliseTimestamp($row->occurred_at)]));
        }

        $body = $lines === [] ? '' : implode("\n", $lines)."\n";
        $sha = hash('sha256', $body);
        $exportedAt = Carbon::now('UTC');
        $manifest = ['_manifest' => [
            'record_count' => count($lines),
            'records_sha256' => $sha,
            'exported_at' => $exportedAt->format('Y-m-d\TH:i:s\Z'),
        ]];
        $manifest['_manifest']['signature'] = $this->crypto->sign($this->crypto->canonicalJson($manifest['_manifest']));

        return [
            'content' => $body.$this->crypto->canonicalJson($manifest)."\n",
            'sha256' => $sha,
            'signature' => $manifest['_manifest']['signature'],
            'count' => count($lines),
            'filename' => 'audit-bundle-'.$exportedAt->format('Ymd-His').'.jsonl',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Enterprise\AuditLedger\Application\DTOs\AuditEntryData;
use Enterprise\AuditLedger\Application\Queries\SearchAuditEntriesQuery;
use Enterprise\AuditLedger\Contracts\PackageSigner;
use Enterprise\AuditLedger\Domain\Services\CanonicalSerializer;
use Enterprise\AuditLedger\Domain\Services\IntegrityVerifier;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Models\AuditExport;
use Enterprise\AuditLedger\Support\ChainReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Builds the forensic export package of a tenant: entries.csv, entries.json, summary.pdf and manifest.json in one zip. The
 * manifest holds the sequence bounds, the hashes at both ends, the result of verifying the chain and the SHA-256 of every
 * file; the SHA-256 of the zip is signed (Mod 144 through PackageSigner) and stored with the export.
 */
final class AuditPackageBuilder
{
    private const array CSV_HEADER = [
        'sequence', 'occurred_at', 'category', 'module', 'action', 'resource_type', 'resource_id', 'company_id', 'actor_type',
        'actor', 'impersonator', 'channel', 'ip_address', 'request_id', 'changes', 'previous_hash', 'hash', 'key_version',
    ];

    public function __construct(
        private readonly SearchAuditEntriesQuery $search,
        private readonly Pseudonymizer $pseudonymizer,
        private readonly IntegrityVerifier $verifier,
        private readonly ChainReader $reader,
        private readonly PackageSigner $signer,
    ) {}

    /**
     * Builds the package, stores it on the export disk and completes the export record.
     */
    public function build(AuditExport $export): AuditExport
    {
        $tenantId = (string) $export->tenant_id;
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'audit-export-'.$export->id;

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The export working directory cannot be created.');
        }

        try {
            $stats = $this->writeEntries($export, $directory);
            $verification = $this->verifier->verify($this->reader->entries($tenantId), $this->reader->anchors($tenantId))->toArray();

            file_put_contents($directory.'/summary.pdf', $this->summaryPdf($export, $stats, $verification));

            $files = [];
            foreach (['entries.csv', 'entries.json', 'summary.pdf'] as $name) {
                $files[$name] = hash_file('sha256', $directory.'/'.$name);
            }

            $manifest = [
                'export_id' => (string) $export->id,
                'tenant_id' => $tenantId,
                'generated_at' => CanonicalSerializer::timestamp(Carbon::now('UTC')),
                'from_date' => $export->from_date->toDateString(),
                'to_date' => $export->to_date->toDateString(),
                'filters' => $export->filters ?? [],
                'entries_count' => $stats['count'],
                'from_sequence' => $stats['from_sequence'],
                'to_sequence' => $stats['to_sequence'],
                'first_previous_hash' => $stats['first_previous_hash'],
                'start_hash' => $stats['start_hash'],
                'end_hash' => $stats['end_hash'],
                'chain_verification' => $verification,
                'files' => $files,
            ];
            file_put_contents($directory.'/manifest.json', (string) json_encode(CanonicalSerializer::canonicalize($manifest), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $zipPath = $directory.'/package.zip';
            $this->zip($zipPath, $directory);
            $sha256 = (string) hash_file('sha256', $zipPath);

            $diskName = (string) config('audit_ledger.export.disk');
            $path = trim((string) config('audit_ledger.export.prefix'), '/').'/'.$tenantId.'/'.$export->id.'.zip';
            $stream = fopen($zipPath, 'rb');
            Storage::disk($diskName)->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $export->forceFill([
                'status' => AuditExport::STATUS_COMPLETED,
                'from_sequence' => $stats['from_sequence'],
                'to_sequence' => $stats['to_sequence'],
                'start_hash' => $stats['start_hash'],
                'end_hash' => $stats['end_hash'],
                'entries_count' => $stats['count'],
                'verification_outcome' => $verification['outcome'],
                'disk' => $diskName,
                'path' => $path,
                'sha256' => $sha256,
                'signature' => $this->signer->sign($tenantId, $sha256),
                'completed_at' => Carbon::now('UTC'),
                'error' => null,
            ])->save();

            return $export;
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    /**
     * @return array{count: int, from_sequence: int|null, to_sequence: int|null, first_previous_hash: string|null, start_hash: string|null, end_hash: string|null}
     */
    private function writeEntries(AuditExport $export, string $directory): array
    {
        $tenantId = (string) $export->tenant_id;
        $filters = (array) ($export->filters ?? []);
        $filters['from'] = $export->from_date->toDateString();
        $filters['to'] = $export->to_date->toDateString();
        $limit = (int) config('audit_ledger.export.max_entries', 500000);

        $csv = fopen($directory.'/entries.csv', 'wb');
        $json = fopen($directory.'/entries.json', 'wb');

        if ($csv === false || $json === false) {
            throw new RuntimeException('The export files cannot be created.');
        }

        fputcsv($csv, self::CSV_HEADER, ',', '"', '');
        fwrite($json, '{"entries":[');

        $stats = ['count' => 0, 'from_sequence' => null, 'to_sequence' => null, 'first_previous_hash' => null, 'start_hash' => null, 'end_hash' => null];
        $last = 0;

        while ($stats['count'] < $limit) {
            $rows = $this->search->filtered($tenantId, $filters)->where('sequence', '>', $last)->orderBy('sequence')->limit(min(500, $limit - $stats['count']))->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach (AuditEntryData::collect($tenantId, $rows, $this->pseudonymizer) as $entry) {
                $changes = (string) json_encode($entry->changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                fputcsv($csv, array_map(self::safeCell(...), [
                    $entry->sequence, $entry->occurredAt, $entry->category, $entry->moduleCode, $entry->action, $entry->resourceType,
                    $entry->resourceId, $entry->companyId, $entry->actorType, $entry->displayActor, $entry->displayImpersonator,
                    $entry->context['channel'] ?? null, $entry->context['ip_address'] ?? null, $entry->context['request_id'] ?? null,
                    $changes, $entry->previousHash, $entry->hash, $entry->keyVersion,
                ]), ',', '"', '');

                fwrite($json, ($stats['count'] > 0 ? ',' : '').json_encode([
                    'sequence' => $entry->sequence,
                    'occurred_at' => $entry->occurredAt,
                    'category' => $entry->category,
                    'module' => $entry->moduleCode,
                    'action' => $entry->action,
                    'resource_type' => $entry->resourceType,
                    'resource_id' => $entry->resourceId,
                    'company_id' => $entry->companyId,
                    'actor_type' => $entry->actorType,
                    'actor' => $entry->displayActor,
                    'impersonator' => $entry->displayImpersonator,
                    'context' => $entry->context,
                    'changes' => $entry->changes,
                    'previous_hash' => $entry->previousHash,
                    'hash' => $entry->hash,
                    'key_version' => $entry->keyVersion,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                $stats['from_sequence'] ??= $entry->sequence;
                $stats['first_previous_hash'] ??= $entry->previousHash;
                $stats['start_hash'] ??= $entry->hash;
                $stats['to_sequence'] = $entry->sequence;
                $stats['end_hash'] = $entry->hash;
                $stats['count']++;
                $last = $entry->sequence;
            }
        }

        fwrite($json, ']}');
        fclose($csv);
        fclose($json);

        return $stats;
    }

    /**
     * Neutralises spreadsheet formulas (CSV injection) in a cell.
     */
    private static function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $verification
     */
    private function summaryPdf(AuditExport $export, array $stats, array $verification): string
    {
        $rows = [
            [__('audit_ledger.export.pdf.tenant'), (string) $export->tenant_id],
            [__('audit_ledger.export.pdf.period'), $export->from_date->toDateString().' - '.$export->to_date->toDateString()],
            [__('audit_ledger.export.pdf.entries'), (string) $stats['count']],
            [__('audit_ledger.export.pdf.sequences'), ($stats['from_sequence'] ?? '-').' - '.($stats['to_sequence'] ?? '-')],
            [__('audit_ledger.export.pdf.start_hash'), (string) ($stats['start_hash'] ?? '-')],
            [__('audit_ledger.export.pdf.end_hash'), (string) ($stats['end_hash'] ?? '-')],
            [__('audit_ledger.export.pdf.verification'), (string) $verification['outcome']],
            [__('audit_ledger.export.pdf.generated'), Carbon::now('UTC')->format('Y-m-d H:i:s').' UTC'],
        ];

        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px}td{padding:4px;border-bottom:1px solid #ccc;word-break:break-all}</style></head><body>'
            .'<h2>'.e(__('audit_ledger.export.pdf.title')).'</h2><table width="100%">';

        foreach ($rows as [$label, $value]) {
            $html .= '<tr><td width="30%"><b>'.e((string) $label).'</b></td><td>'.e($value).'</td></tr>';
        }

        $html .= '</table><p>'.e(__('audit_ledger.export.pdf.note')).'</p></body></html>';

        return Pdf::loadHTML($html)->output();
    }

    private function zip(string $zipPath, string $directory): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export package cannot be created.');
        }

        foreach (['entries.csv', 'entries.json', 'summary.pdf', 'manifest.json'] as $name) {
            $zip->addFile($directory.'/'.$name, $name);
        }

        $zip->close();
    }
}

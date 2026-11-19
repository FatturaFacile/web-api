<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance;

use App\Models\Privacy\ConsentLedgerEntry;
use App\Models\Privacy\PrivacySubjectRequestDsar;
use App\Models\Privacy\ProcessingActivityRecordRopa;
use App\Services\Privacy\SubjectDataExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Compiles the data portability package of a DSAR (GDPR art. 15/20, nLPD art. 25/28): a signed ZIP with the machine
 * readable JSON and the human readable PDF. Tables are read in bounded queries through SubjectDataExporter.
 */
final readonly class DsarPortablePackageService
{
    public function __construct(private SubjectDataExporter $exporter) {}

    /**
     * Builds the package, stores it on the private disk and records its path on the request.
     */
    public function compile(PrivacySubjectRequestDsar $dsar): string
    {
        $dsar->update(['package_status' => 'processing']);

        try {
            $json = $this->payload($dsar);
            $encoded = (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $number = $dsar->request_number;
            $path = "privacy/dsar/{$dsar->tenant_id}/{$number}.zip";
            $tmp = tempnam(sys_get_temp_dir(), 'dsar');
            if ($tmp === false) {
                throw new RuntimeException('Cannot create the package file.');
            }

            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot open the package archive.');
            }

            $pdf = $this->pdf($dsar, $json);
            $zip->addFromString("dsar_export_{$number}.json", $encoded);
            $zip->addFromString("dsar_report_{$number}.pdf", $pdf);
            $zip->addFromString('manifest.sig', json_encode([
                'algorithm' => 'HMAC-SHA256',
                'files' => [
                    "dsar_export_{$number}.json" => hash_hmac('sha256', $encoded, (string) config('app.key')),
                    "dsar_report_{$number}.pdf" => hash_hmac('sha256', $pdf, (string) config('app.key')),
                ],
            ], JSON_PRETTY_PRINT));
            $zip->close();

            Storage::disk('local')->put($path, (string) file_get_contents($tmp));
            @unlink($tmp);
            $dsar->update(['export_artifact_path' => $path, 'package_status' => 'ready']);

            return $path;
        } catch (Throwable $e) {
            $dsar->update(['package_status' => 'failed']);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(PrivacySubjectRequestDsar $dsar): array
    {
        $tenantId = (string) $dsar->tenant_id;
        $registryType = $dsar->subject_type->registryType();
        $dossier = ['tables' => [], 'summary' => []];
        if ($registryType !== null && $dsar->subject_entity_id !== null) {
            $dossier = $this->exporter->collect($tenantId, $registryType, (string) $dsar->subject_entity_id);
        }

        $hash = ConsentLedgerService::identifierHash($dsar->subject_email);
        $consents = ConsentLedgerEntry::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)->where('subject_identifier_hash', $hash)->orderBy('sequence')->get()
            ->map(static fn (ConsentLedgerEntry $e): array => [
                'purpose_code' => $e->purpose_code,
                'consent_granted' => $e->consent_granted,
                'policy_version' => $e->policy_version,
                'captured_at' => $e->captured_at->toIso8601String(),
                'proof_channel' => $e->proof_channel->value,
            ])->all();

        $audit = [];
        if ($dsar->subject_entity_id !== null) {
            $audit = AuditLedgerEntry::query()->where('tenant_id', $tenantId)->where('resource_id', (string) $dsar->subject_entity_id)
                ->orderBy('event_timestamp')->limit(1000)->get(['action', 'module_code', 'event_timestamp'])->toArray();
        }

        return [
            'schema' => 'urn:fatturafacile:privacy:dsar-export:1.0',
            'regulations' => ['GDPR Art. 15 & 20', 'Swiss nLPD Art. 25 & 28'],
            'request_number' => $dsar->request_number,
            'subject' => ['type' => $dsar->subject_type->value, 'full_name' => $dsar->subject_full_name, 'email' => $dsar->subject_email],
            'generated_at' => now()->toIso8601String(),
            'modules' => $dossier['tables'],
            'summary' => $dossier['summary'],
            'consent_history' => $consents,
            'audit_log' => $audit,
            'processing_activities' => ProcessingActivityRecordRopa::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('is_active', true)->get(['activity_code', 'activity_name', 'legal_basis', 'retention_period_months', 'retention_legal_reference'])->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pdf(PrivacySubjectRequestDsar $dsar, array $payload): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 11px">';
        $html .= '<h1>'.$e($dsar->request_number).' — Data Subject Access Report</h1>';
        $html .= '<p>'.$e($dsar->subject_full_name).' &lt;'.$e($dsar->subject_email).'&gt; · '.$e($payload['generated_at']).'</p>';
        $html .= '<h2>Data by module</h2><ul>';
        foreach ($payload['summary'] as $table => $count) {
            $html .= '<li>'.$e($table).': '.$e($count).' records</li>';
        }

        $html .= '</ul><h2>Legal basis and retention</h2><ul>';
        foreach ($payload['processing_activities'] as $activity) {
            $html .= '<li>'.$e($activity['activity_code']).' — '.$e($activity['activity_name']).' ('.$e($activity['legal_basis']).', '.$e($activity['retention_period_months']).' months; '.$e($activity['retention_legal_reference']).')</li>';
        }

        $html .= '</ul><h2>Consent history</h2><ul>';
        foreach ($payload['consent_history'] as $consent) {
            $html .= '<li>'.$e($consent['captured_at']).' '.$e($consent['purpose_code']).': '.($consent['consent_granted'] ? 'granted' : 'revoked').' (policy '.$e($consent['policy_version']).')</li>';
        }

        $html .= '</ul></body></html>';

        return Pdf::loadHTML($html)->output();
    }
}

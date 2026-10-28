<?php

declare(strict_types=1);

namespace App\Actions\ServiceReports;

use App\Events\ServiceReports\ServiceReportSigned;
use App\Jobs\ServiceReports\GenerateServiceReportPdfJob;
use App\Models\LookupItem;
use App\Models\ServiceReport;
use App\Models\ServiceReportSignature;
use App\Rules\PngSignature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SignServiceReportAction
{
    /**
     * Record touchscreen signature on service report.
     *
     * @param  array{
     *     signature_base64: string,
     *     signer_name: string,
     *     signer_role?: ?string,
     *     signer_email?: ?string,
     *     ip_address: string,
     *     user_agent?: ?string
     * }  $data
     */
    public function execute(ServiceReport $report, array $data): ServiceReport
    {
        return DB::transaction(function () use ($report, $data): ServiceReport {
            $report = ServiceReport::lockForUpdate()->findOrFail($report->id);

            if ($report->signed_at !== null) {
                throw ValidationException::withMessages([
                    'service_report' => 'This service report is already signed.',
                ]);
            }

            $binary = PngSignature::decode($data['signature_base64']);

            if ($binary === null) {
                throw ValidationException::withMessages([
                    'signature_base64' => 'Invalid PNG signature image payload.',
                ]);
            }

            $disk = config('filesystems.private_disk', config('filesystems.default', 'local'));
            $signatureHash = hash('sha256', $binary);
            $path = "tenants/{$report->tenant_id}/signatures/service-reports/{$report->id}.png";
            Storage::disk($disk)->put($path, $binary);

            $signature = ServiceReportSignature::create([
                'tenant_id' => $report->tenant_id,
                'service_report_id' => $report->id,
                'signer_type' => 'customer',
                'signer_name' => $data['signer_name'],
                'signer_role' => $data['signer_role'] ?? null,
                'signer_email' => $data['signer_email'] ?? null,
                'signature_path' => $path,
                'signature_hash' => $signatureHash,
                'ip_address' => $data['ip_address'],
                'user_agent' => $data['user_agent'] ?? null,
                'signed_at' => now(),
                'created_by' => Auth::id(),
            ]);

            $signedStatus = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_status'))
                ->where('code', 'signed')
                ->first();

            $report->update([
                'status_id' => $signedStatus?->id ?? $report->status_id,
                'signed_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            event(new ServiceReportSigned($report, $signature));

            GenerateServiceReportPdfJob::dispatch($report)->afterCommit();

            return $report->load(['signatures', 'lines.lineType', 'contact', 'technician', 'status']);
        });
    }
}

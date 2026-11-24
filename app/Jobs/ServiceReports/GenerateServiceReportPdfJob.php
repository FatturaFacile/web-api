<?php

declare(strict_types=1);

namespace App\Jobs\ServiceReports;

use App\Models\Attachment;
use App\Models\ServiceReport;
use App\Services\Pdf\DocumentPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateServiceReportPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly ServiceReport $serviceReport)
    {
        $this->afterCommit();
    }

    public function handle(DocumentPdfService $pdfService): void
    {
        $rendered = $pdfService->render($this->serviceReport);

        $disk = config('filesystems.private_disk', config('filesystems.default', 'local'));
        $path = "tenants/{$this->serviceReport->tenant_id}/service-reports/{$this->serviceReport->id}.pdf";
        Storage::disk($disk)->put($path, $rendered->content);

        Attachment::updateOrCreate(
            [
                'tenant_id' => $this->serviceReport->tenant_id,
                'attachable_type' => ServiceReport::class,
                'attachable_id' => $this->serviceReport->id,
                'original_name' => $rendered->filename,
            ],
            [
                'uploaded_by' => $this->serviceReport->created_by,
                'path' => $path,
                'mime_type' => 'application/pdf',
                'size' => strlen($rendered->content),
            ]
        );
    }
}

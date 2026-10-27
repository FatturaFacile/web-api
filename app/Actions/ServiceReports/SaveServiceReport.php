<?php

declare(strict_types=1);

namespace App\Actions\ServiceReports;

use App\Events\ServiceReports\ServiceReportCreated;
use App\Models\LookupItem;
use App\Models\ServiceReport;
use App\Services\Sales\DocumentNumberingService;
use App\Services\ServiceReports\ServiceReportCalculationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveServiceReport
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly ServiceReportCalculationService $calculator,
    ) {}

    /**
     * Create a new service report with lines.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ServiceReport
    {
        return DB::transaction(function () use ($data): ServiceReport {
            $linesData = $data['lines'] ?? [];
            unset($data['lines']);

            $year = ! empty($data['report_date'])
                ? (int) date('Y', strtotime((string) $data['report_date']))
                : (int) date('Y');

            $number = $this->numbering->next('service_report', $year);
            $data['report_number'] = $number['number'];

            if (empty($data['status_id'])) {
                $draftStatus = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_status'))
                    ->where('code', 'draft')
                    ->first();
                $data['status_id'] = $draftStatus?->id;
            }

            if (empty($data['created_by']) && Auth::check()) {
                $data['created_by'] = Auth::id();
            }

            /** @var ServiceReport $report */
            $report = ServiceReport::create($data);

            foreach ($linesData as $lineItem) {
                $computed = $this->calculator->computeLineAmounts($lineItem);
                $computed['created_by'] = Auth::id();
                $report->lines()->create($computed);
            }

            $report = $this->calculator->recalculateReportTotals($report);

            event(new ServiceReportCreated($report));

            return $report->load(['lines.lineType', 'contact', 'technician', 'status']);
        });
    }

    /**
     * Update an existing service report and its lines.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ServiceReport $report, array $data): ServiceReport
    {
        return DB::transaction(function () use ($report, $data): ServiceReport {
            $report = ServiceReport::lockForUpdate()->findOrFail($report->id);

            if ($report->signed_at !== null) {
                throw ValidationException::withMessages([
                    'service_report' => 'Signed service reports cannot be modified.',
                ]);
            }

            if ($report->invoiced_at !== null) {
                throw ValidationException::withMessages([
                    'service_report' => 'Invoiced service reports cannot be modified.',
                ]);
            }

            $linesData = $data['lines'] ?? null;
            unset($data['lines']);

            if (Auth::check()) {
                $data['updated_by'] = Auth::id();
            }

            $report->update($data);

            if ($linesData !== null) {
                $report->lines()->delete();

                foreach ($linesData as $lineItem) {
                    $computed = $this->calculator->computeLineAmounts($lineItem);
                    $computed['created_by'] = Auth::id();
                    $report->lines()->create($computed);
                }
            }

            $report = $this->calculator->recalculateReportTotals($report);

            return $report->load(['lines.lineType', 'contact', 'technician', 'status']);
        });
    }
}

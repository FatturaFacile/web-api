<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use App\Models\Contact;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\ServiceManagement\FieldService\ServiceReportPdfRenderer;
use Modules\ServiceManagement\FieldService\ServiceReportSeal;
use Modules\ServiceManagement\Mail\ServiceReportSignedMail;
use Modules\ServiceManagement\Models\DigitalServiceReport;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Models\WorkOrderPhoto;
use Modules\ServiceManagement\Services\ServiceAmounts;

/**
 * Signs and closes the service report of a work order (GH-202).
 *
 * Idempotent on `client_uuid`: a replayed offline sync returns the report created the first time (its
 * `wasRecentlyCreated` flag is false) without touching meters, counters or e-mail again. On first success it seals the
 * report with a SHA-256 hash, completes the work order, advances the equipment meters and the contract visit counter,
 * renders the localized PDF and queues it to the customer contact.
 */
final readonly class SignAndCloseServiceReportHandler
{
    public const SvgMaxBytes = 262144;

    public function __construct(
        private ServiceReportPdfRenderer $renderer,
        private AuditLedgerInterface $auditLedger,
    ) {}

    public function __invoke(SignAndCloseServiceReportCommand $command): DigitalServiceReport
    {
        $report = DB::transaction(fn (): DigitalServiceReport => $this->sign($command));

        if ($report->wasRecentlyCreated) {
            $this->deliver($report);
        }

        return $report;
    }

    /**
     * The inline photo references of the command plus the uploaded photos it names by id, which must all belong to the
     * work order (and so to the tenant); anything else is rejected as a validation error.
     *
     * @return list<array<string, mixed>>
     */
    private function referencedPhotos(WorkOrder $workOrder, SignAndCloseServiceReportCommand $command): array
    {
        $photos = $command->photos;

        if ($command->photoIds === []) {
            return $photos;
        }

        $uploaded = WorkOrderPhoto::query()
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('work_order_id', $workOrder->id)
            ->whereIn('id', $command->photoIds)
            ->get()
            ->keyBy(static fn (WorkOrderPhoto $photo): string => (string) $photo->id);

        if ($uploaded->count() !== count(array_unique($command->photoIds))) {
            throw ValidationException::withMessages(['photo_ids' => __('field_service.errors.photos_not_in_work_order')]);
        }

        foreach ($command->photoIds as $id) {
            $photo = $uploaded[$id];
            $photos[] = array_filter([
                'id' => (string) $photo->id,
                'path' => $photo->path,
                'name' => $photo->caption,
                'stage' => $photo->stage,
                'taken_at' => $photo->captured_at?->toIso8601String(),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $photos;
    }

    private function sign(SignAndCloseServiceReportCommand $command): DigitalServiceReport
    {
        $workOrder = WorkOrder::query()->whereKey($command->workOrderId)->lockForUpdate()->firstOrFail();

        if ($command->clientUuid !== null) {
            $replay = DigitalServiceReport::query()->where('tenant_id', $workOrder->tenant_id)->where('client_uuid', $command->clientUuid)->first();

            if ($replay !== null) {
                return $replay;
            }
        }

        if (DigitalServiceReport::query()->where('work_order_id', $workOrder->id)->exists()) {
            throw ValidationException::withMessages(['work_order' => __('field_service.errors.report_exists')]);
        }

        if (! in_array($workOrder->status, ['dispatched', 'en_route', 'on_site', 'in_progress'], true)) {
            throw ValidationException::withMessages(['work_order' => __('field_service.errors.report_not_open')]);
        }

        if (strlen($command->signatureSvg) > self::SvgMaxBytes || ! str_starts_with(ltrim($command->signatureSvg), '<svg')) {
            throw ValidationException::withMessages(['signature_svg' => 'invalid']);
        }

        $passport = $workOrder->equipment_passport_id === null
            ? null
            : SerializedEquipmentPassport::query()->where('tenant_id', $workOrder->tenant_id)->whereKey($workOrder->equipment_passport_id)->lockForUpdate()->first();

        if ($passport !== null) {
            $this->assertMeterMonotonic($passport, $command);
        }

        $photos = $this->referencedPhotos($workOrder, $command);

        $number = $this->nextReportNumber((string) $workOrder->tenant_id, $command->signedAt);
        $labor = ServiceAmounts::text($command->laborHours);
        $travel = ServiceAmounts::text($command->travelHours);

        $hash = ServiceReportSeal::compute([
            'work_order_id' => (string) $workOrder->id,
            'report_number' => $number,
            'summary' => $command->workPerformedSummary,
            'labor_hours' => number_format((float) $labor, 2, '.', ''),
            'travel_hours' => number_format((float) $travel, 2, '.', ''),
            'travel_km' => number_format((float) $command->travelKm, 2, '.', ''),
            'signer_name' => $command->signerName,
            'signer_role' => $command->signerRole,
            'signed_at' => $command->signedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'signature' => $command->signatureSvg,
        ]);

        $report = DigitalServiceReport::create([
            'tenant_id' => $workOrder->tenant_id,
            'company_id' => $workOrder->company_id,
            'work_order_id' => $workOrder->id,
            'report_number' => $number,
            'work_performed_summary' => $command->workPerformedSummary,
            'checklist_json' => $command->checklist,
            'labor_hours' => $labor,
            'travel_hours' => $travel,
            'travel_km' => $command->travelKm,
            'apply_callout_fee' => $command->applyCalloutFee,
            'meter_reading_hours' => $command->meterReadingHours,
            'meter_reading_cycles' => $command->meterReadingCycles,
            'customer_signer_name' => $command->signerName,
            'customer_signer_role' => $command->signerRole,
            'signature_svg_data' => $command->signatureSvg,
            'signature_sha256_hash' => $hash,
            'signed_at' => $command->signedAt,
            'gps_latitude' => $command->gpsLatitude,
            'gps_longitude' => $command->gpsLongitude,
            'photo_attachments_json' => $photos,
            'locale' => $command->locale,
            'client_uuid' => $command->clientUuid,
        ]);

        $workOrder->update([
            'status' => 'completed',
            'actual_end' => $command->signedAt,
            'customer_signed' => true,
            'signed_by_name' => $command->signerName,
            'signature_data' => $hash,
            'signed_at' => $command->signedAt,
            'resolution_notes' => $command->workPerformedSummary,
        ]);

        if ($passport !== null) {
            $this->advanceMeters($passport, $workOrder, $command);
        }

        $this->countVisit($workOrder, $command);

        $this->auditLedger->record('SERVICE_REPORT_SIGNED', $report, null, ['report_number' => $number, 'sha256' => $hash], 'MODULE_17_ESM');

        return $report;
    }

    private function assertMeterMonotonic(SerializedEquipmentPassport $passport, SignAndCloseServiceReportCommand $command): void
    {
        $lowerHours = $command->meterReadingHours !== null && (float) $command->meterReadingHours < (float) $passport->current_operating_hours;
        $lowerCycles = $command->meterReadingCycles !== null && $command->meterReadingCycles < (int) $passport->current_cycle_count;

        if ($lowerHours || $lowerCycles) {
            throw ValidationException::withMessages(['meter_reading' => __('field_service.errors.meter_regression')]);
        }
    }

    /**
     * Records the readings as the new counters and as the baseline of the next maintenance cycle, so the preventive
     * engine starts counting again from this service.
     */
    private function advanceMeters(SerializedEquipmentPassport $passport, WorkOrder $workOrder, SignAndCloseServiceReportCommand $command): void
    {
        $hours = $command->meterReadingHours ?? (string) $passport->current_operating_hours;
        $cycles = $command->meterReadingCycles ?? (int) $passport->current_cycle_count;
        $contract = $workOrder->service_contract_id === null
            ? null
            : ServiceContract::query()->where('tenant_id', $workOrder->tenant_id)->find($workOrder->service_contract_id);
        $calendar = (array) Arr::get((array) $contract?->preventive_frequency_rule, 'calendar', []);

        $passport->update([
            'current_operating_hours' => $hours,
            'current_cycle_count' => $cycles,
            'last_service_operating_hours' => $hours,
            'last_service_cycle_count' => $cycles,
            'last_service_date' => $command->signedAt->toDateString(),
            'next_scheduled_service_date' => GeneratePreventiveMaintenanceWorkOrdersHandler::calendarDueAfter($calendar, $command->signedAt)?->toDateString(),
        ]);
    }

    private function countVisit(WorkOrder $workOrder, SignAndCloseServiceReportCommand $command): void
    {
        if ($workOrder->service_contract_id === null) {
            return;
        }

        $contract = ServiceContract::query()->where('tenant_id', $workOrder->tenant_id)->whereKey($workOrder->service_contract_id)->lockForUpdate()->first();

        if ($contract === null) {
            return;
        }

        $year = (int) $command->signedAt->format('Y');
        $used = (int) $contract->visits_counter_year === $year ? (int) $contract->used_visits_current_year : 0;

        $contract->update(['used_visits_current_year' => $used + 1, 'visits_counter_year' => $year]);
    }

    private function nextReportNumber(string $tenantId, \DateTimeInterface $date): string
    {
        $year = $date->format('Y');
        $sequence = DigitalServiceReport::withTrashed()->where('tenant_id', $tenantId)->where('report_number', 'like', "SR-{$year}-%")->count() + 1;

        do {
            $number = sprintf('SR-%s-%04d', $year, $sequence++);
        } while (DigitalServiceReport::withTrashed()->where('tenant_id', $tenantId)->where('report_number', $number)->exists());

        return $number;
    }

    /**
     * Renders and stores the PDF, then queues it to the customer contact when it has an e-mail address.
     */
    private function deliver(DigitalServiceReport $report): void
    {
        $path = "field-service/reports/{$report->tenant_id}/{$report->report_number}.pdf";
        Storage::disk('local')->put($path, $this->renderer->render($report));
        $report->update(['pdf_path' => $path]);

        $workOrder = WorkOrder::query()->where('tenant_id', $report->tenant_id)->find($report->work_order_id);
        $contact = $workOrder?->customer_id === null ? null : Contact::query()->where('tenant_id', $report->tenant_id)->find($workOrder->customer_id);

        if ($contact !== null && filled($contact->email)) {
            Mail::to((string) $contact->email)->queue(new ServiceReportSignedMail((string) $report->report_number, $path, (string) $report->locale));
            $report->update(['emailed_at' => now()]);
        }
    }
}

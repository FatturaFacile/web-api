<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService;

use App\Contracts\PdfGenerator;
use App\Models\Contact;
use Closure;
use Illuminate\Support\Facades\App;
use Modules\ServiceManagement\Models\DigitalServiceReport;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Renders the signed service report as a localized PDF (it-IT / it-CH Rapportino d'Intervento, de-CH Servicerapport,
 * fr-CH Rapport d'intervention). Locales are BCP-47 tags; their language part selects the translation file.
 */
final readonly class ServiceReportPdfRenderer
{
    /** @var array<string, string> */
    private const Languages = ['it' => 'it', 'de' => 'de', 'fr' => 'fr', 'en' => 'en'];

    public function __construct(
        private PdfGenerator $pdf,
        private ServiceReportBilling $billing,
    ) {}

    public static function languageOf(string $locale): string
    {
        return self::Languages[strtolower(substr($locale, 0, 2))] ?? 'en';
    }

    public function render(DigitalServiceReport $report): string
    {
        $workOrder = WorkOrder::query()->where('tenant_id', $report->tenant_id)->findOrFail($report->work_order_id);
        $customer = $workOrder->customer_id === null ? null : Contact::query()->where('tenant_id', $report->tenant_id)->find($workOrder->customer_id);
        $technician = $workOrder->assigned_technician_id === null ? null : ServiceTechnician::query()->where('tenant_id', $report->tenant_id)->find($workOrder->assigned_technician_id);
        $passport = $workOrder->equipment_passport_id === null ? null : SerializedEquipmentPassport::query()->where('tenant_id', $report->tenant_id)->find($workOrder->equipment_passport_id);

        $data = [
            'report' => $report,
            'workOrder' => $workOrder,
            'billing' => $this->billing->breakdown($report),
            'customerName' => $customer === null ? '-' : (string) ($customer->company_name ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))),
            'technicianName' => $technician === null ? '-' : (string) $technician->name,
            'siteAddress' => (string) ($passport->site_address ?? ($workOrder->location['address'] ?? '-')),
            'equipmentLabel' => $passport === null ? '-' : (string) $passport->serial_number,
        ];

        return self::inLocale((string) $report->locale, fn (): string => $this->pdf->render('field-service::service-report', $data));
    }

    /**
     * Runs the callback with the app locale switched to the language of the report, restoring it afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function inLocale(string $locale, Closure $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale(self::languageOf($locale));

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}

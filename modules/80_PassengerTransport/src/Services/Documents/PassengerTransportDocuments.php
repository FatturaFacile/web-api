<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Models\ServiceSheet;

/**
 * The printable documents: the NCC electronic service sheet (FDSE), the EU Interbus waybill with the passenger manifest and the VIP
 * meet-and-greet welcome sign. Every label is read from the backend dictionary (lang/*\/passenger_transport.php) in the active locale.
 */
final class PassengerTransportDocuments
{
    public function serviceSheetPdf(ServiceSheet $sheet, string $companyName): string
    {
        $booking = $sheet->booking;
        $rows = [
            ['number', $sheet->number()],
            ['company', $companyName],
            ['vehicle_plate', $sheet->vehicle?->license_plate],
            ['ncc_authorization', $sheet->vehicle?->ncc_authorization_number],
            ['garage_municipality', $sheet->vehicle?->ncc_issuing_municipality],
            ['driver', $booking->driver?->full_name],
            ['customer', $booking->orderingCustomer->company_name ?? $booking->lead_passenger_name],
            ['lead_passenger', $booking->lead_passenger_name],
            ['pickup', $booking->pickup_address],
            ['dropoff', $booking->dropoff_address],
            ['garage_departure', $this->time($sheet->garage_departure_at).' / '.$sheet->garage_departure_km.' km'],
            ['service_start', $this->time($sheet->service_start_at).' / '.$sheet->service_start_km.' km'],
            ['service_end', $this->time($sheet->service_end_at).' / '.$sheet->service_end_km.' km'],
            ['garage_return', $this->time($sheet->garage_return_at).' / '.$sheet->garage_return_km.' km'],
            ['fdse_protocol', $sheet->mit_fdse_protocol_code_it],
            ['signature_hash', $sheet->digital_signature_hash],
        ];

        return $this->pdf($this->page(__('passenger_transport.documents.service_sheet_title'), $this->table($rows)), 'portrait');
    }

    public function interbusPdf(ServiceSheet $sheet, string $companyName): string
    {
        $booking = $sheet->booking;
        $manifest = '';

        foreach ($sheet->passenger_manifest ?? [] as $index => $passenger) {
            $manifest .= '<tr><td>'.($index + 1).'</td><td>'.e((string) ($passenger['name'] ?? '')).'</td><td>'.e((string) ($passenger['birth_date'] ?? '')).'</td><td>'.e((string) ($passenger['document'] ?? '')).'</td><td>'.e((string) ($passenger['nationality'] ?? '')).'</td></tr>';
        }

        $head = $this->table([
            ['waybill_number', $sheet->interbus_eu_waybill_number],
            ['company', $companyName],
            ['vehicle_plate', $sheet->vehicle?->license_plate],
            ['driver', $booking->driver?->full_name],
            ['pickup', $booking->pickup_address],
            ['dropoff', $booking->dropoff_address],
            ['border_crossing', $booking->border_crossing_point->value],
            ['service_start', $this->time($sheet->service_start_at)],
        ]);
        $table = '<h3>'.e(__('passenger_transport.documents.manifest')).'</h3><table border="1" cellpadding="4" cellspacing="0" width="100%"><tr><th>#</th><th>'
            .e(__('passenger_transport.documents.passenger_name')).'</th><th>'.e(__('passenger_transport.documents.birth_date')).'</th><th>'
            .e(__('passenger_transport.documents.document')).'</th><th>'.e(__('passenger_transport.documents.nationality')).'</th></tr>'.$manifest.'</table>';

        return $this->pdf($this->page(__('passenger_transport.documents.interbus_title'), $head.$table), 'portrait');
    }

    public function welcomeSignPdf(PassengerBooking $booking): string
    {
        $text = $booking->vip_welcome_sign_text ?? $booking->lead_passenger_name ?? '';
        $logo = $this->logoDataUri($booking->vip_welcome_logo_path);
        $logoHtml = $logo === null ? '' : '<div style="text-align:center"><img src="'.$logo.'" style="max-height:140px"></div>';
        $html = '<div style="text-align:center;padding-top:60px;font-family:DejaVu Sans,sans-serif">'.$logoHtml
            .'<div style="font-size:28px;margin-top:40px;color:#555">'.e(__('passenger_transport.documents.welcome')).'</div>'
            .'<div style="font-size:72px;font-weight:bold;margin-top:30px">'.e($text).'</div></div>';

        return $this->pdf('<html><body>'.$html.'</body></html>', 'landscape');
    }

    /**
     * The welcome sign as the client shows it: the text, the greeting and the logo as a data URI.
     *
     * @return array{text: string, greeting: string, logo_data_uri: string|null}
     */
    public function welcomeSign(PassengerBooking $booking): array
    {
        return [
            'text' => (string) ($booking->vip_welcome_sign_text ?? $booking->lead_passenger_name ?? ''),
            'greeting' => (string) __('passenger_transport.documents.welcome'),
            'logo_data_uri' => $this->logoDataUri($booking->vip_welcome_logo_path),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string|null}>  $rows
     */
    private function table(array $rows): string
    {
        $html = '<table border="1" cellpadding="5" cellspacing="0" width="100%">';

        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th align="left" width="35%">'.e(__('passenger_transport.documents.'.$label)).'</th><td>'.e((string) $value).'</td></tr>';
        }

        return $html.'</table>';
    }

    private function page(string $title, string $body): string
    {
        return '<html><body style="font-family:DejaVu Sans,sans-serif;font-size:12px"><h2>'.e($title).'</h2>'.$body.'</body></html>';
    }

    private function pdf(string $html, string $orientation): string
    {
        return Pdf::loadHTML($html)->setPaper('a4', $orientation)->output();
    }

    private function time(mixed $value): string
    {
        return $value === null ? '-' : $value->format('d/m/Y H:i');
    }

    private function logoDataUri(?string $path): ?string
    {
        $disk = Storage::disk((string) config('passenger_transport.storage_disk'));

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        $mime = $disk->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
    }
}

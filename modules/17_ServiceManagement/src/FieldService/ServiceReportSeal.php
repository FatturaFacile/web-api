<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService;

use Modules\ServiceManagement\Models\DigitalServiceReport;

/**
 * The SHA-256 integrity seal of a signed service report: it covers the signature image together with what the customer
 * signed for (report number, work order, summary, hours, signer and signing time), so any later change to the report or
 * to the signature is detectable with {@see self::verify()}.
 */
final class ServiceReportSeal
{
    /**
     * @param  array{work_order_id: string, report_number: string, summary: string, labor_hours: string, travel_hours: string, travel_km: string, signer_name: string, signer_role: string|null, signed_at: string, signature: string}  $content
     */
    public static function compute(array $content): string
    {
        $content['signature'] = hash('sha256', $content['signature']);
        ksort($content);

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function verify(DigitalServiceReport $report): bool
    {
        $expected = self::compute([
            'work_order_id' => (string) $report->work_order_id,
            'report_number' => (string) $report->report_number,
            'summary' => (string) $report->work_performed_summary,
            'labor_hours' => (string) $report->labor_hours,
            'travel_hours' => (string) $report->travel_hours,
            'travel_km' => (string) $report->travel_km,
            'signer_name' => (string) $report->customer_signer_name,
            'signer_role' => $report->customer_signer_role,
            'signed_at' => $report->signed_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'signature' => (string) ($report->signature_svg_data ?? ''),
        ]);

        return hash_equals($report->signature_sha256_hash, $expected);
    }
}

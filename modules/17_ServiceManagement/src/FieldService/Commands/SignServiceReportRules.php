<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use Carbon\CarbonImmutable;

/**
 * The single validation contract of the signed service report, shared by the web endpoint and the offline sync so both
 * clients are held to the same rules, and the translation of the validated payload into the command.
 */
final class SignServiceReportRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'client_uuid' => ['nullable', 'string', 'max:64'],
            'work_performed_summary' => ['required', 'string', 'max:5000'],
            'checklist' => ['nullable', 'array', 'max:100'],
            'checklist.*.item' => ['required', 'string', 'max:200'],
            'checklist.*.result' => ['required', 'in:pass,fail,na'],
            'checklist.*.notes' => ['nullable', 'string', 'max:1000'],
            'labor_hours' => ['required', 'numeric', 'min:0', 'max:999'],
            'travel_hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'travel_km' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'apply_callout_fee' => ['nullable', 'boolean'],
            'meter_reading_hours' => ['nullable', 'numeric', 'min:0'],
            'meter_reading_cycles' => ['nullable', 'integer', 'min:0'],
            'customer_signer_name' => ['required', 'string', 'max:150'],
            'customer_signer_role' => ['nullable', 'string', 'max:100'],
            'signature_svg' => ['required', 'string', 'max:'.SignAndCloseServiceReportHandler::SvgMaxBytes, 'regex:/^\s*<svg[\s>]/i', 'not_regex:/<\s*script|[\s"\']on[a-z]+\s*=|javascript:/i'],
            'signed_at' => ['required', 'date'],
            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photos' => ['nullable', 'array', 'max:20'],
            'photos.*.path' => ['required_with:photos', 'string', 'max:255'],
            'photos.*.name' => ['nullable', 'string', 'max:150'],
            'photos.*.taken_at' => ['nullable', 'date'],
            'locale' => ['nullable', 'in:it-IT,it-CH,de-CH,fr-CH,en'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated against {@see self::rules()}
     */
    public static function command(string $workOrderId, array $data, ?string $actorUserId): SignAndCloseServiceReportCommand
    {
        return new SignAndCloseServiceReportCommand(
            workOrderId: $workOrderId,
            workPerformedSummary: (string) $data['work_performed_summary'],
            signerName: (string) $data['customer_signer_name'],
            signatureSvg: (string) $data['signature_svg'],
            signedAt: CarbonImmutable::parse((string) $data['signed_at']),
            clientUuid: $data['client_uuid'] ?? null,
            signerRole: $data['customer_signer_role'] ?? null,
            checklist: array_values((array) ($data['checklist'] ?? [])),
            laborHours: (string) $data['labor_hours'],
            travelHours: (string) ($data['travel_hours'] ?? '0'),
            travelKm: (string) ($data['travel_km'] ?? '0'),
            applyCalloutFee: (bool) ($data['apply_callout_fee'] ?? false),
            meterReadingHours: isset($data['meter_reading_hours']) ? (string) $data['meter_reading_hours'] : null,
            meterReadingCycles: isset($data['meter_reading_cycles']) ? (int) $data['meter_reading_cycles'] : null,
            gpsLatitude: isset($data['gps_latitude']) ? (float) $data['gps_latitude'] : null,
            gpsLongitude: isset($data['gps_longitude']) ? (float) $data['gps_longitude'] : null,
            photos: array_values((array) ($data['photos'] ?? [])),
            locale: (string) ($data['locale'] ?? 'it-IT'),
            actorUserId: $actorUserId,
        );
    }
}

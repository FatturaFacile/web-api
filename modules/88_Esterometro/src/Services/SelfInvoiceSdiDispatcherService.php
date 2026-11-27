<?php

declare(strict_types=1);

namespace Modules\Esterometro\Services;

use App\Contracts\SdiProvider;
use App\Models\SdiConfiguration;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Esterometro\Domain\Enums\SdiTransmissionStatus;
use Modules\Esterometro\Domain\Enums\SelfInvoiceStatus;
use Modules\Esterometro\Exceptions\EsterometroException;
use Modules\Esterometro\Models\SelfInvoice;
use Modules\Esterometro\Models\SelfInvoiceSdiLog;
use Modules\Esterometro\Models\SelfInvoiceSetting;
use Modules\SDI\Services\DigitalSignatureService;
use Throwable;

/**
 * Signs and transmits TD16–TD28 self-invoice XML via SDI and processes RC/NS notifications.
 */
final class SelfInvoiceSdiDispatcherService
{
    public function __construct(
        private readonly SelfInvoiceGeneratorService $generator,
        private readonly DigitalSignatureService $signatureService,
        private readonly SdiProvider $sdiProvider,
    ) {}

    public function dispatch(SelfInvoice $selfInvoice, ?string $actorUserId = null): SelfInvoice
    {
        if (in_array($selfInvoice->status, [SelfInvoiceStatus::SentToSdi, SelfInvoiceStatus::DeliveredRc, SelfInvoiceStatus::Cancelled], true)) {
            throw EsterometroException::invalidStateTransition($selfInvoice->status->value, SelfInvoiceStatus::SentToSdi->value);
        }

        $selfInvoice = $this->generator->validateAndBuildXml($selfInvoice, $actorUserId, true);
        $tenant = Tenant::query()->find($selfInvoice->tenant_id);
        if ($tenant === null) {
            throw EsterometroException::missingTenantFiscalData('tenant');
        }

        $xml = (string) $selfInvoice->xml_content;
        $vatDigits = preg_replace('/[^A-Z0-9]/i', '', strtoupper((string) ($tenant->vat_number ?? $tenant->tax_id ?? '00000000000'))) ?? '00000000000';
        $progressive = $selfInvoice->sdi_progressive ?: str_pad((string) $selfInvoice->sequential_number, 5, '0', STR_PAD_LEFT);
        $fileName = "IT{$vatDigits}_{$progressive}.xml";
        $payloadToSend = $xml;
        $signedPath = null;

        $settings = SelfInvoiceSetting::query()->where('tenant_id', $selfInvoice->tenant_id)->first();
        $signatureFormat = $settings?->signature_format ?? 'none';

        if ($signatureFormat !== 'none') {
            $sdiConfig = SdiConfiguration::query()->where('tenant_id', $selfInvoice->tenant_id)->first();
            if ($sdiConfig !== null) {
                $signed = $this->signatureService->sign($sdiConfig, $xml, $fileName, $signatureFormat);
                $fileName = $signed->fileName;
                $payloadToSend = $signed->content;
                $signedPath = "esterometro/{$selfInvoice->tenant_id}/{$fileName}";
                Storage::disk('local')->put($signedPath, $payloadToSend);
            }
        }

        $prevStatus = $selfInvoice->status->value;
        $selfInvoice->forceFill([
            'status' => SelfInvoiceStatus::PendingSend,
            'sdi_status' => SdiTransmissionStatus::PendingSend,
            'sdi_filename' => $fileName,
            'signed_storage_path' => $signedPath,
        ])->save();

        try {
            $result = $this->sdiProvider->send($fileName, $payloadToSend);
            $sdiUuid = (string) ($result['uuid'] ?? '');

            $selfInvoice->forceFill([
                'status' => SelfInvoiceStatus::SentToSdi,
                'sdi_status' => SdiTransmissionStatus::SentToSdi,
                'sdi_identificativo' => $sdiUuid !== '' ? $sdiUuid : $selfInvoice->sdi_identificativo,
                'sent_at' => Carbon::now(),
                'rejected_at' => null,
                'rejection_code' => null,
                'rejection_description' => null,
            ])->save();

            SelfInvoiceSdiLog::create([
                'tenant_id' => $selfInvoice->tenant_id,
                'self_invoice_id' => $selfInvoice->id,
                'event_type' => 'SENT_TO_SDI',
                'from_status' => $prevStatus,
                'to_status' => SelfInvoiceStatus::SentToSdi->value,
                'sdi_identificativo' => $sdiUuid,
                'sdi_filename' => $fileName,
                'raw_payload' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_by' => $actorUserId,
            ]);
        } catch (Throwable $e) {
            $selfInvoice->forceFill([
                'status' => SelfInvoiceStatus::FailedTransmission,
                'sdi_status' => SdiTransmissionStatus::FailedTransmission,
                'rejection_description' => $e->getMessage(),
            ])->save();

            SelfInvoiceSdiLog::create([
                'tenant_id' => $selfInvoice->tenant_id,
                'self_invoice_id' => $selfInvoice->id,
                'event_type' => 'TRANSMISSION_FAILED',
                'from_status' => $prevStatus,
                'to_status' => SelfInvoiceStatus::FailedTransmission->value,
                'sdi_filename' => $fileName,
                'error_description' => $e->getMessage(),
                'created_by' => $actorUserId,
            ]);

            throw EsterometroException::sdiDispatchFailed($e->getMessage());
        }

        return $selfInvoice->fresh(['lines', 'sdiLogs']) ?? $selfInvoice;
    }

    /**
     * Process an incoming SDI receipt notification (RC = Ricevuta di Consegna, NS = Notifica di Scarto).
     */
    public function handleNotification(
        SelfInvoice $selfInvoice,
        string $notificationType,
        ?string $sdiIdentificativo = null,
        ?string $errorCode = null,
        ?string $errorDescription = null,
        ?string $rawPayload = null,
    ): SelfInvoice {
        $normalized = strtoupper(trim($notificationType));
        $fromStatus = $selfInvoice->status->value;

        if ($normalized === 'RC') {
            $selfInvoice->forceFill([
                'status' => SelfInvoiceStatus::DeliveredRc,
                'sdi_status' => SdiTransmissionStatus::DeliveredRc,
                'sdi_identificativo' => $sdiIdentificativo ?: $selfInvoice->sdi_identificativo,
                'delivered_at' => Carbon::now(),
                'rejection_code' => null,
                'rejection_description' => null,
            ])->save();

            SelfInvoiceSdiLog::create([
                'tenant_id' => $selfInvoice->tenant_id,
                'self_invoice_id' => $selfInvoice->id,
                'event_type' => 'RECEIPT_RC',
                'from_status' => $fromStatus,
                'to_status' => SelfInvoiceStatus::DeliveredRc->value,
                'sdi_identificativo' => $selfInvoice->sdi_identificativo,
                'sdi_filename' => $selfInvoice->sdi_filename,
                'raw_payload' => $rawPayload,
            ]);
        } elseif ($normalized === 'NS') {
            $selfInvoice->forceFill([
                'status' => SelfInvoiceStatus::RejectedNs,
                'sdi_status' => SdiTransmissionStatus::RejectedNs,
                'sdi_identificativo' => $sdiIdentificativo ?: $selfInvoice->sdi_identificativo,
                'rejected_at' => Carbon::now(),
                'rejection_code' => $errorCode ?: 'NS',
                'rejection_description' => $errorDescription ?: 'Notifica di Scarto ricevuta da SDI.',
            ])->save();

            SelfInvoiceSdiLog::create([
                'tenant_id' => $selfInvoice->tenant_id,
                'self_invoice_id' => $selfInvoice->id,
                'event_type' => 'REJECTION_NS',
                'from_status' => $fromStatus,
                'to_status' => SelfInvoiceStatus::RejectedNs->value,
                'sdi_identificativo' => $selfInvoice->sdi_identificativo,
                'sdi_filename' => $selfInvoice->sdi_filename,
                'error_code' => $errorCode ?: 'NS',
                'error_description' => $errorDescription,
                'raw_payload' => $rawPayload,
            ]);
        }

        return $selfInvoice->fresh(['lines', 'sdiLogs']) ?? $selfInvoice;
    }
}

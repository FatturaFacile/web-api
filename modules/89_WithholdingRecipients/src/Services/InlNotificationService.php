<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Services;

use App\Services\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Modules\WithholdingRecipients\Domain\Enums\InlNotificationStatus;
use Modules\WithholdingRecipients\Exceptions\WithholdingRecipientException;
use Modules\WithholdingRecipients\Models\OccasionalInlNotification;
use Modules\WithholdingRecipients\Models\RecipientProfile;

/**
 * Preventive INL communication of occasional self-employed work (Art. 14 D.Lgs. 81/2008): builds the payload, the XML and the
 * PDF to send before the work starts, records the dispatch, and gates the payment of the compensation.
 */
final class InlNotificationService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $data  work_start_date, work_end_date, activity_description, work_place, estimated_compensation
     */
    public function generate(RecipientProfile $profile, array $data, ?string $userId): OccasionalInlNotification
    {
        if ($profile->regime !== 'occasional_worker') {
            throw new WithholdingRecipientException('Only occasional workers need the INL communication.', 'inl_not_applicable');
        }

        return DB::transaction(function () use ($profile, $data, $userId): OccasionalInlNotification {
            $notification = new OccasionalInlNotification([
                'tenant_id' => (string) $this->tenantContext->getTenantId(),
                'recipient_profile_id' => $profile->id,
                'status' => InlNotificationStatus::Draft->value,
                'work_start_date' => $data['work_start_date'],
                'work_end_date' => $data['work_end_date'],
                'activity_description' => $data['activity_description'],
                'work_place' => $data['work_place'] ?? null,
                'estimated_compensation' => $data['estimated_compensation'] ?? 0,
                'created_by' => $userId,
            ]);
            $notification->payload = $this->payload($profile, $notification);
            $notification->xml = $this->xml($notification->payload);
            $notification->save();

            return $notification;
        });
    }

    public function markSent(OccasionalInlNotification $notification, ?string $protocolReference, ?CarbonImmutable $sentAt, ?string $userId): OccasionalInlNotification
    {
        if ($notification->status === InlNotificationStatus::Cancelled->value) {
            throw new WithholdingRecipientException('A cancelled communication cannot be sent.', 'inl_cancelled');
        }

        $notification->update([
            'status' => InlNotificationStatus::Sent->value,
            'sent_at' => $sentAt ?? CarbonImmutable::now(),
            'protocol_reference' => $protocolReference,
            'updated_by' => $userId,
        ]);

        return $notification->refresh();
    }

    public function cancel(OccasionalInlNotification $notification, ?string $userId): OccasionalInlNotification
    {
        $notification->update(['status' => InlNotificationStatus::Cancelled->value, 'updated_by' => $userId]);

        return $notification->refresh();
    }

    /**
     * The notification that covers a service, or the reason why none does.
     *
     * @throws WithholdingRecipientException inl_notification_missing | inl_notification_late | inl_notification_expired
     */
    public function gate(RecipientProfile $profile, CarbonImmutable $serviceDate): OccasionalInlNotification
    {
        $sent = OccasionalInlNotification::query()
            ->where('recipient_profile_id', $profile->id)
            ->where('status', InlNotificationStatus::Sent->value)
            ->orderByDesc('work_start_date')
            ->get();

        if ($sent->isEmpty()) {
            throw new WithholdingRecipientException('No INL preventive communication on file.', 'inl_notification_missing');
        }

        $covering = $sent->first(static fn (OccasionalInlNotification $n): bool => $n->coversService($serviceDate));

        if ($covering !== null) {
            return $covering;
        }

        $inPeriod = $sent->first(static fn (OccasionalInlNotification $n): bool => $serviceDate->between($n->work_start_date->startOfDay(), $n->work_end_date->endOfDay()));

        throw $inPeriod !== null
            ? new WithholdingRecipientException('The INL communication was sent after the work started.', 'inl_notification_late')
            : new WithholdingRecipientException('The INL communication does not cover the date of the service.', 'inl_notification_expired');
    }

    /**
     * Whether a service date is covered, without raising (for the compliance alerts).
     */
    public function isCovered(RecipientProfile $profile, CarbonImmutable $serviceDate): bool
    {
        try {
            $this->gate($profile, $serviceDate);

            return true;
        } catch (WithholdingRecipientException) {
            return false;
        }
    }

    public function pdf(OccasionalInlNotification $notification, string $locale): string
    {
        $payload = $notification->payload ?? [];
        $t = static fn (string $key): string => e(__('withholding_recipients.inl.'.$key, [], $locale));
        $rows = '';

        foreach ([
            'client' => $payload['committente']['denominazione'] ?? '',
            'client_tax_id' => $payload['committente']['codice_fiscale'] ?? '',
            'worker' => $payload['prestatore']['nominativo'] ?? '',
            'worker_tax_id' => $payload['prestatore']['codice_fiscale'] ?? '',
            'activity' => $payload['prestazione']['descrizione'] ?? '',
            'place' => $payload['prestazione']['luogo'] ?? '',
            'start' => $payload['prestazione']['data_inizio'] ?? '',
            'end' => $payload['prestazione']['data_fine'] ?? '',
            'compensation' => $payload['prestazione']['compenso_presunto'] ?? '',
            'recipient' => $payload['destinatario'] ?? '',
        ] as $label => $value) {
            $rows .= '<tr><th>'.$t($label).'</th><td>'.e((string) $value).'</td></tr>';
        }

        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px}th{text-align:left;width:34%;padding:4px}td{padding:4px}table{width:100%}</style></head><body>'
            .'<h2>'.$t('title').'</h2><p>'.$t('legal_basis').'</p><table>'.$rows.'</table></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RecipientProfile $profile, OccasionalInlNotification $notification): array
    {
        $tenant = $this->tenantContext->getTenant();

        return [
            'riferimento_normativo' => 'Art. 14 D.Lgs. 81/2008',
            'destinatario' => (string) config('withholding_recipients.inl.recipient_email'),
            'committente' => [
                'denominazione' => (string) ($tenant->legal_name ?? $tenant->name ?? ''),
                'codice_fiscale' => (string) ($tenant->fiscal_code ?? ''),
                'partita_iva' => (string) ($tenant->vat_number ?? ''),
            ],
            'prestatore' => [
                'nominativo' => $profile->display_name,
                'codice_fiscale' => (string) $profile->tax_code,
                'data_nascita' => $profile->birth_date?->toDateString(),
            ],
            'prestazione' => [
                'descrizione' => $notification->activity_description,
                'luogo' => (string) $notification->work_place,
                'data_inizio' => $notification->work_start_date->toDateString(),
                'data_fine' => $notification->work_end_date->toDateString(),
                'compenso_presunto' => number_format((float) $notification->estimated_compensation, 2, '.', ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function xml(array $payload): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElement('ComunicazioneLavoroAutonomoOccasionale');
        $document->appendChild($root);

        $append = static function (\DOMElement $parent, array $values) use ($document, &$append): void {
            foreach ($values as $name => $value) {
                $element = $document->createElement(str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $name))));
                $parent->appendChild($element);

                if (is_array($value)) {
                    $append($element, $value);
                } else {
                    $element->appendChild($document->createTextNode((string) $value));
                }
            }
        };
        $append($root, $payload);

        return (string) $document->saveXML();
    }
}

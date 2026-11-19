<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Enums\SdiNotificationType;
use Modules\SDI\Models\FpaDocument;
use Modules\SDI\Models\SdiNotification;

/**
 * Applies an SDI receipt to an electronic invoice envelope: stores it in the notification history and drives the
 * state machine. Idempotent per (document, type, message id).
 */
final class SdiNotificationProcessorService
{
    /** Days a public administration has to answer before the deadline passes (DT). */
    public const int PaResponseDays = 15;

    /** Calendar days the sender has to correct and resubmit a rejected file. */
    public const int ResubmissionDays = 5;

    public function __construct(private readonly SdiLifecycleStateMachine $machine) {}

    public function process(FpaDocument $document, SdiNotificationType $type, string $xml): SdiNotification
    {
        $data = $this->parse($xml);

        return DB::transaction(function () use ($document, $type, $xml, $data): SdiNotification {
            $existing = SdiNotification::withoutGlobalScopes()
                ->where('fpa_document_id', $document->id)
                ->where('notification_type', $type->value)
                ->where('sdi_message_id', $data['message_id'])
                ->first();

            if ($existing !== null && $data['message_id'] !== null) {
                return $existing;
            }

            $path = sprintf('sdi/%s/%s/%s_%s.xml', $document->tenant_id, $document->id, $type->value, now()->format('YmdHisu'));
            Storage::disk('local')->put($path, $xml);

            $notification = SdiNotification::withoutGlobalScopes()->create([
                'tenant_id' => $document->tenant_id,
                'fpa_document_id' => $document->id,
                'notification_type' => $type,
                'sdi_identifier' => $data['sdi_identifier'] ?? $document->sdi_identifier,
                'sdi_message_id' => $data['message_id'],
                'outcome_code' => $data['outcome'],
                'error_codes' => $data['errors'] === [] ? null : $data['errors'],
                'raw_xml_path' => $path,
                'received_at' => now(),
                'processed_at' => now(),
            ]);

            try {
                $this->apply($document, $type, $data);
            } catch (\DomainException) {
                // Late or out-of-order receipt (for example an MC after the RC): kept in the history, state unchanged.
            }

            return $notification;
        });
    }

    /**
     * Public administration invoices not answered within 15 days pass to DT (tacit acceptance).
     */
    public function expirePaDeadlines(): int
    {
        $count = 0;

        FpaDocument::withoutGlobalScopes()
            ->where('format_transmission', 'FPA12')
            ->whereIn('status', [FpaStatus::Delivered->value, FpaStatus::Undelivered->value, FpaStatus::Transmitted->value])
            ->where('transmitted_at', '<=', now()->subDays(self::PaResponseDays))
            ->each(function (FpaDocument $document) use (&$count): void {
                $this->process($document, SdiNotificationType::DT, sprintf(
                    '<NotificaDecorrenzaTermini><IdentificativoSdI>%s</IdentificativoSdI></NotificaDecorrenzaTermini>',
                    htmlspecialchars((string) $document->sdi_identifier, ENT_XML1),
                ));
                $count++;
            });

        return $count;
    }

    /**
     * @param  array{outcome: ?string, errors: list<array{code: string, description: string, line_reference: ?string}>, delivered_at: ?string}  $data
     */
    private function apply(FpaDocument $document, SdiNotificationType $type, array $data): void
    {
        match ($type) {
            SdiNotificationType::NS => $this->machine->transition($document, FpaStatus::Rejected, [
                'rejected_at' => now(),
                'resubmission_deadline_at' => now()->addDays(self::ResubmissionDays),
                'validation_errors' => $data['errors'],
            ]),
            SdiNotificationType::RC => $this->machine->transition($document, FpaStatus::Delivered),
            SdiNotificationType::MC => $this->machine->transition($document, FpaStatus::Undelivered),
            SdiNotificationType::NE => $this->machine->transition(
                $document,
                $data['outcome'] === 'EC02' ? FpaStatus::PaRejected : FpaStatus::PaAccepted,
            ),
            SdiNotificationType::DT => $this->machine->transition($document, FpaStatus::DeadlinePassed),
            SdiNotificationType::AT => $this->machine->transition($document, FpaStatus::TransmittedAttested),
            SdiNotificationType::MT => null,
        };
    }

    /**
     * @return array{sdi_identifier: ?string, message_id: ?string, outcome: ?string, errors: list<array{code: string, description: string, line_reference: ?string}>, delivered_at: ?string}
     */
    private function parse(string $xml): array
    {
        $dom = new DOMDocument;
        $out = ['sdi_identifier' => null, 'message_id' => null, 'outcome' => null, 'errors' => [], 'delivered_at' => null];

        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            return $out;
        }

        $xp = new DOMXPath($dom);
        $text = static function (string $name, ?\DOMNode $context = null) use ($xp): ?string {
            $node = $xp->query('.//*[local-name()="'.$name.'"]', $context)->item(0);

            return $node !== null ? trim($node->textContent) : null;
        };

        $out['sdi_identifier'] = $text('IdentificativoSdI');
        $out['message_id'] = $text('MessageId');
        $out['outcome'] = $text('Esito');
        $out['delivered_at'] = $text('DataOraConsegna');

        foreach ($xp->query('//*[local-name()="Errore"]') ?: [] as $error) {
            $out['errors'][] = [
                'code' => (string) $text('Codice', $error),
                'description' => (string) $text('Descrizione', $error),
                'line_reference' => $text('Suggerimento', $error),
            ];
        }

        return $out;
    }
}

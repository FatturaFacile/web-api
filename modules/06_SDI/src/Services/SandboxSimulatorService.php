<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use Modules\SDI\Enums\SdiNotificationType;
use Modules\SDI\Models\FpaDocument;

/**
 * Sandbox SDI: fabricates the receipt XML SDI would send (RC, MC, NS, NE, DT, AT) and feeds it to the notification
 * processor, so the full lifecycle can be exercised locally and on staging without a real channel.
 */
final class SandboxSimulatorService
{
    public function __construct(private readonly SdiNotificationProcessorService $processor) {}

    public function simulate(
        FpaDocument $document,
        SdiNotificationType $type,
        ?string $outcome = null,
        ?string $errorCode = null,
        ?string $errorDescription = null,
    ): void {
        $id = $this->e((string) ($document->sdi_identifier ?? ''));
        $file = $this->e((string) $document->file_name);
        $message = bin2hex(random_bytes(6));

        $xml = match ($type) {
            SdiNotificationType::NS => sprintf(
                '<NotificaScarto><IdentificativoSdI>%s</IdentificativoSdI><NomeFile>%s</NomeFile><MessageId>%s</MessageId><ListaErrori><Errore><Codice>%s</Codice><Descrizione>%s</Descrizione></Errore></ListaErrori></NotificaScarto>',
                $id, $file, $message, $this->e($errorCode ?? '00404'), $this->e($errorDescription ?? 'Simulated rejection'),
            ),
            SdiNotificationType::NE => sprintf('<NotificaEsito><IdentificativoSdI>%s</IdentificativoSdI><MessageId>%s</MessageId><Esito>%s</Esito></NotificaEsito>', $id, $message, $outcome === 'EC02' ? 'EC02' : 'EC01'),
            SdiNotificationType::RC => sprintf('<RicevutaConsegna><IdentificativoSdI>%s</IdentificativoSdI><NomeFile>%s</NomeFile><DataOraConsegna>%s</DataOraConsegna><MessageId>%s</MessageId></RicevutaConsegna>', $id, $file, now()->toIso8601String(), $message),
            default => sprintf('<Notifica><IdentificativoSdI>%s</IdentificativoSdI><NomeFile>%s</NomeFile><MessageId>%s</MessageId></Notifica>', $id, $file, $message),
        };

        $this->processor->process($document, $type, $xml);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1);
    }
}

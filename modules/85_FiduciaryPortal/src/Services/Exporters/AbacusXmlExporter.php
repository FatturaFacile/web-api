<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Support\Collection;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Swiss Abacus AbaConnect XML journal export format.
 */
final class AbacusXmlExporter extends AbstractStudioCsvExporter
{
    public function format(): StudioExportFormat
    {
        return StudioExportFormat::AbacusXml;
    }

    public function generate(
        Tenant $tenant,
        Collection $entries,
        array $transcodingMap,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $currency,
        array $options = [],
    ): array {
        $applyTranscoding = (bool) ($options['apply_transcoding'] ?? true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('AbaConnectContainer');
        $dom->appendChild($root);

        $task = $dom->createElement('Task');
        $task->setAttribute('Count', (string) $entries->count());
        $root->appendChild($task);

        $parameter = $dom->createElement('Parameter');
        $parameter->appendChild($dom->createElement('Application', 'FIBU'));
        $parameter->appendChild($dom->createElement('MapId', 'AbaDefault'));
        $parameter->appendChild($dom->createElement('Version', '2024.00'));
        $parameter->appendChild($dom->createElement('Mandant', (string) ($tenant->uid_number ?: $tenant->name)));
        $task->appendChild($parameter);

        $rowsCount = 0;

        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            $pairs = $this->pairEntryLines($entry, $transcodingMap, $applyTranscoding, $currency);
            if ($pairs === []) {
                continue;
            }

            $transaction = $dom->createElement('Transaction');
            $transaction->setAttribute('EntryNumber', (string) $entry->entry_number);
            $transaction->setAttribute('FiduciaryVerified', $entry->getAttribute('fiduciary_verified') ? 'true' : 'false');

            foreach ($pairs as $pair) {
                $booking = $dom->createElement('Booking');
                $booking->setAttribute('mode', 'SAVE');
                $booking->appendChild($dom->createElement('DocumentNumber', (string) ($entry->getAttribute('document_number') ?: $entry->entry_number)));
                $booking->appendChild($dom->createElement('EntryDate', $entry->posting_date?->toDateString() ?? $from->toDateString()));
                $booking->appendChild($dom->createElement('Account', $pair['debit_code']));
                $booking->appendChild($dom->createElement('ContraAccount', $pair['credit_code']));
                $booking->appendChild($dom->createElement('Amount', $pair['amount']));
                $booking->appendChild($dom->createElement('Currency', $pair['currency']));
                $booking->appendChild($dom->createElement('ExchangeRate', $pair['exchange_rate']));
                $booking->appendChild($dom->createElement('TaxCode', $pair['vat_code']));
                $booking->appendChild($dom->createElement('Text', mb_substr($pair['text'], 0, 120)));
                $transaction->appendChild($booking);
                $rowsCount++;
            }

            $task->appendChild($transaction);
        }

        return [
            'content' => (string) $dom->saveXML(),
            'file_name' => "abaconnect_{$from->format('Ymd')}_{$to->format('Ymd')}.xml",
            'mime_type' => $this->format()->mimeType(),
            'rows_count' => $rowsCount,
        ];
    }
}

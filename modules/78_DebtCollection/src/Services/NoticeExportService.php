<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Models\Contact;
use Modules\DebtCollection\Models\Notice;
use ZipArchive;

/**
 * The export package of a notice for archiving or for posting by registered mail: the notice PDF, the text sent, a
 * manifest with the PEC Message-ID and the receipts recorded (acceptance and delivery) and the calculation lines.
 */
final class NoticeExportService
{
    public function __construct(private readonly NoticeRenderer $renderer) {}

    public function package(Notice $notice): string
    {
        $notice->loadMissing(['lines', 'receipts']);
        $contact = Contact::query()->find($notice->customer_id);

        $manifest = [
            'number' => $notice->number,
            'kind' => $notice->kind,
            'level' => (int) $notice->level,
            'channel' => $notice->channel,
            'status' => $notice->status,
            'issued_on' => $notice->issued_on->toDateString(),
            'sent_at' => $notice->sent_at?->toIso8601String(),
            'recipient' => ['pec' => $contact?->pec, 'email' => $contact?->email],
            'pec_message_id' => $notice->pec_message_id,
            'amounts' => ['currency' => $notice->currency, 'principal' => (string) $notice->principal, 'interest' => (string) $notice->interest, 'fee' => (string) $notice->fee, 'total' => (string) $notice->total],
            'lines' => $notice->lines->map(static fn ($line): array => ['document' => $line->document_number, 'due_date' => $line->due_date->toDateString(), 'principal' => (string) $line->principal, 'interest' => (string) $line->interest, 'days' => (int) $line->interest_days, 'rate_percent' => (string) $line->rate_percent])->all(),
            'receipts' => $notice->receipts->map(static fn ($receipt): array => ['kind' => $receipt->kind, 'message_id' => $receipt->message_id, 'received_at' => $receipt->received_at->toIso8601String()])->all(),
        ];

        $path = tempnam(sys_get_temp_dir(), 'dcn');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($notice->number.'.pdf', $this->renderer->pdf($notice));
        $zip->addFromString('message.txt', (string) $notice->subject."\n\n".(string) $notice->body);
        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }
}

<?php

declare(strict_types=1);

namespace Modules\DataExport\Services;

use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/**
 * ZIP for the accountant: the FatturaPA files of a period, issued and received, with a CSV index. An issued document
 * without a stored XML stays in the index as `xml_missing`, so the gap is visible.
 */
final readonly class AccountantArchiveBuilder
{
    /**
     * @var list<string>
     */
    private const array IndexHeader = ['direction', 'document_number', 'document_date', 'counterparty', 'vat_number', 'document_type', 'total', 'currency', 'file', 'status'];

    public function __construct(private ExcelCsvWriter $csv) {}

    /**
     * @return string path of the temporary ZIP file
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'accountant-archive-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The archive cannot be created.');
        }

        $index = [...$this->issued($zip, $from, $to), ...$this->received($zip, $from, $to)];

        $zip->addFromString('index.csv', $this->csv->write(self::IndexHeader, $index));
        $zip->close();

        return $path;
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function issued(ZipArchive $zip, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        $invoices = Invoice::with('contact')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('invoice_type', '!=', 'proforma')
            ->orderBy('date')
            ->get();

        $metadata = InvoiceSdiMetadata::whereIn('invoice_id', $invoices->pluck('id'))->whereNotNull('xml_content')->get()->keyBy('invoice_id');

        foreach ($invoices as $invoice) {
            $sdi = $metadata->get($invoice->id);
            $file = null;

            if ($sdi !== null) {
                $file = 'issued/'.$this->fileName((string) ($sdi->file_name ?: $invoice->invoice_number));
                $zip->addFromString($file, (string) $sdi->xml_content);
            }

            $rows[] = [
                'issued', $invoice->invoice_number, $invoice->date?->toDateString(), $this->counterparty($invoice->contact), $invoice->contact?->vat_number,
                $invoice->invoice_type, (string) $invoice->total_gross, $invoice->currency, $file, $file !== null ? 'included' : 'xml_missing',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function received(ZipArchive $zip, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        $invoices = PassiveInvoice::with('supplier')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();

        foreach ($invoices as $invoice) {
            $file = null;

            if ($invoice->xml_payload !== null) {
                $file = 'received/'.$this->fileName((string) ($invoice->sdi_file_name ?: $invoice->id));

                if ($zip->locateName($file) === false) {
                    $zip->addFromString($file, (string) $invoice->xml_payload);
                }
            }

            $rows[] = [
                'received', $invoice->invoice_number, $invoice->date?->toDateString(), $this->counterparty($invoice->supplier), $invoice->supplier?->vat_number,
                $invoice->document_type, (string) $invoice->total_amount, $invoice->currency, $file, $file !== null ? 'included' : 'xml_missing',
            ];
        }

        return $rows;
    }

    private function counterparty(mixed $contact): string
    {
        return (string) ($contact?->company_name ?: trim($contact?->first_name.' '.$contact?->last_name));
    }

    private function fileName(string $name): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', basename(str_replace('\\', '/', $name)));

        return str_ends_with(strtolower($safe), '.xml') || str_ends_with(strtolower($safe), '.p7m') ? $safe : "{$safe}.xml";
    }
}

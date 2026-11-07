<?php

declare(strict_types=1);

namespace Modules\DataExport\Services;

use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * ZIP for the accountant: the FatturaPA files of a period, issued and received, with a CSV index. An issued document
 * without a stored XML stays in the index as `xml_missing`, so the gap is visible.
 *
 * The period is at most one year ({@see self::MaxPeriod}). Documents are read in pages of {@see self::PageSize}, in date
 * order, and the XML of a page is written to the archive before the next page is read: memory holds one page, not the
 * whole period. The index is written to a temporary file as the pages go by.
 */
final readonly class AccountantArchiveBuilder
{
    /**
     * The longest period of one archive: `to` is at most one year minus a day after `from` (a calendar year fits).
     */
    public const string MaxPeriod = '1 year';

    private const int PageSize = 200;

    /**
     * @var list<string>
     */
    private const array IndexHeader = ['direction', 'document_number', 'document_date', 'counterparty', 'vat_number', 'document_type', 'total', 'currency', 'file', 'status'];

    public function __construct(private ExcelCsvWriter $csv) {}

    /**
     * Whether the period fits in one archive.
     */
    public static function periodFits(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $to->greaterThanOrEqualTo($from) && $to->lessThan($from->add(self::MaxPeriod));
    }

    /**
     * @return string path of the temporary ZIP file
     *
     * @throws InvalidArgumentException when the period is longer than {@see self::MaxPeriod}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if (! self::periodFits($from, $to)) {
            throw new InvalidArgumentException('The period of an accountant archive is at most one year.');
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'accountant-archive-');
        $indexPath = (string) tempnam(sys_get_temp_dir(), 'accountant-index-');
        $index = fopen($indexPath, 'wb') ?: throw new RuntimeException('The archive index cannot be created.');
        $zip = new ZipArchive;

        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The archive cannot be created.');
            }

            fwrite($index, $this->csv->write(self::IndexHeader, []));

            foreach ([$this->issued($from, $to), $this->received($from, $to)] as $pages) {
                foreach ($pages as $page) {
                    $rows = [];

                    foreach ($page as [$row, $file, $xml]) {
                        if ($file !== null && $zip->locateName($file) === false) {
                            $zip->addFromString($file, $xml);
                        }

                        $rows[] = $row;
                    }

                    fwrite($index, $this->csv->rows($rows));
                    $zip = $this->flushed($zip, $path);
                }
            }

            fclose($index);
            $zip->addFile($indexPath, 'index.csv');
            $zip->close();
        } finally {
            if (is_resource($index)) {
                fclose($index);
            }

            @unlink($indexPath);
        }

        return $path;
    }

    /**
     * Pages of issued documents: `[index row, file name or null, XML]` per document.
     *
     * @return Generator<int, list<array{0: array<int, mixed>, 1: ?string, 2: string}>>
     */
    private function issued(CarbonImmutable $from, CarbonImmutable $to): Generator
    {
        $query = Invoice::query()->with('contact')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('invoice_type', '!=', 'proforma');

        foreach ($this->pages($query) as $invoices) {
            $metadata = InvoiceSdiMetadata::whereIn('invoice_id', $invoices->modelKeys())->whereNotNull('xml_content')->get()->keyBy('invoice_id');
            $page = [];

            foreach ($invoices as $invoice) {
                $sdi = $metadata->get($invoice->id);
                $file = $sdi !== null ? 'issued/'.$this->fileName((string) ($sdi->file_name ?: $invoice->invoice_number)) : null;

                $page[] = [[
                    'issued', $invoice->invoice_number, $invoice->date?->toDateString(), $this->counterparty($invoice->contact), $invoice->contact?->vat_number,
                    $invoice->invoice_type, (string) $invoice->total_gross, $invoice->currency, $file, $file !== null ? 'included' : 'xml_missing',
                ], $file, (string) $sdi?->xml_content];
            }

            yield $page;
        }
    }

    /**
     * Pages of received documents: `[index row, file name or null, XML]` per document.
     *
     * @return Generator<int, list<array{0: array<int, mixed>, 1: ?string, 2: string}>>
     */
    private function received(CarbonImmutable $from, CarbonImmutable $to): Generator
    {
        $query = PassiveInvoice::query()->with('supplier')->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        foreach ($this->pages($query) as $invoices) {
            $page = [];

            foreach ($invoices as $invoice) {
                $file = $invoice->xml_payload !== null ? 'received/'.$this->fileName((string) ($invoice->sdi_file_name ?: $invoice->id)) : null;

                $page[] = [[
                    'received', $invoice->invoice_number, $invoice->date?->toDateString(), $this->counterparty($invoice->supplier), $invoice->supplier?->vat_number,
                    $invoice->document_type, (string) $invoice->total_amount, $invoice->currency, $file, $file !== null ? 'included' : 'xml_missing',
                ], $file, (string) $invoice->xml_payload];
            }

            yield $page;
        }
    }

    /**
     * Keyset pages in (date, id) order: every page starts after the last row of the previous one, so a page costs the
     * same at the end of the period as at the start.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Generator<int, Collection<int, TModel>>
     */
    private function pages(Builder $query): Generator
    {
        $table = $query->getModel()->getTable();
        $last = null;

        do {
            $page = (clone $query)
                ->when($last !== null, static fn (Builder $after) => $after->whereRaw("({$table}.date, {$table}.id) > (?, ?)", [$last->date->toDateString(), $last->id]))
                ->orderBy("{$table}.date")
                ->orderBy("{$table}.id")
                ->limit(self::PageSize)
                ->get();

            if ($page->isNotEmpty()) {
                yield $page;
                $last = $page->last();
            }
        } while ($page->count() === self::PageSize);
    }

    /**
     * ZipArchive keeps every added string in memory until the archive is closed: closing and reopening after each page
     * writes the page to disk and frees it.
     */
    private function flushed(ZipArchive $zip, string $path): ZipArchive
    {
        $zip->close();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The archive cannot be reopened.');
        }

        return $zip;
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

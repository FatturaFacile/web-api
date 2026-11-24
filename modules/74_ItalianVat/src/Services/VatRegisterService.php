<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use App\Contracts\PdfGenerator;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\ThirdPartyGoodsMovement;
use Modules\ItalianVat\Models\VatRegisterEntry;
use Modules\ItalianVat\Models\VatRegisterPrint;
use Modules\ItalianVat\Models\VatSection;

/**
 * The Italian VAT registers (DPR 633/1972 art. 23, 24, 25 and 39): progressive protocol numbers per sectional and year,
 * deductible and non-deductible VAT of the purchases (with pro-rata), the totals per rate and nature, and the definitive print
 * that locks the printed lines against silent changes.
 */
final class VatRegisterService
{
    /** Lines of a printed page of the register; the pages of a sectional are numbered progressively within the year. */
    public const int ENTRIES_PER_PAGE = 40;

    private const array NATURES = ['N1', 'N2.1', 'N2.2', 'N3.1', 'N3.2', 'N3.3', 'N3.4', 'N3.5', 'N3.6', 'N4', 'N5', 'N6.1', 'N6.2', 'N6.3', 'N6.4', 'N6.5', 'N6.6', 'N6.7', 'N6.8', 'N6.9', 'N7'];

    public function __construct(
        private readonly VatSectionService $sections,
        private readonly ProRataService $proRata,
        private readonly PdfGenerator $pdfGenerator,
    ) {}

    /**
     * Records a document in a sectional of the sales, purchases or receipts register. Amounts are given as positive numbers
     * and stored with the sign of the document: a credit note reduces the register.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(string $tenantId, ?string $userId, array $data): VatRegisterEntry
    {
        $section = $this->sections->find($tenantId, (string) $data['section_id']);

        if ($section->register_type === VatSectionService::GOODS) {
            throw ItalianVatException::invalid('section_is_goods_register');
        }

        if (! $section->is_active) {
            throw ItalianVatException::invalid('section_inactive', ['code' => $section->code]);
        }

        $entryDate = CarbonImmutable::parse((string) $data['entry_date'])->startOfDay();
        $kind = (string) ($data['document_kind'] ?? $section->document_kind ?? 'ordinary');
        $amounts = $this->amounts($tenantId, $section, $entryDate, $kind, $data);

        return DB::transaction(function () use ($tenantId, $userId, $data, $section, $entryDate, $kind, $amounts): VatRegisterEntry {
            $this->lockSection($section);
            $this->assertPeriodOpen($section, $entryDate);

            return VatRegisterEntry::create($amounts + [
                'tenant_id' => $tenantId,
                'section_id' => $section->id,
                'register_type' => $section->register_type,
                'year' => $entryDate->year,
                'protocol_number' => $this->nextProtocol($section, $entryDate->year),
                'entry_date' => $entryDate->toDateString(),
                'document_date' => isset($data['document_date']) ? CarbonImmutable::parse((string) $data['document_date'])->toDateString() : null,
                'document_number' => $data['document_number'] ?? null,
                'document_kind' => $kind,
                'document_type_code' => $data['document_type_code'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'counterparty_name' => $data['counterparty_name'] ?? null,
                'counterparty_vat' => $data['counterparty_vat'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Cancels a line that was already printed with an opposite one, in the open period: a locked line is never altered.
     */
    public function reverse(string $tenantId, ?string $userId, string $entryId, string $entryDate, ?string $reason = null): VatRegisterEntry
    {
        $original = $this->findEntry($tenantId, $entryId);
        $date = CarbonImmutable::parse($entryDate)->startOfDay();

        return DB::transaction(function () use ($tenantId, $userId, $original, $date, $reason): VatRegisterEntry {
            $section = $this->sections->find($tenantId, $original->section_id);
            $this->lockSection($section);
            $this->assertPeriodOpen($section, $date);

            return VatRegisterEntry::create([
                'tenant_id' => $tenantId,
                'section_id' => $original->section_id,
                'register_type' => $original->register_type,
                'year' => $date->year,
                'protocol_number' => $this->nextProtocol($section, $date->year),
                'entry_date' => $date->toDateString(),
                'document_date' => $original->document_date?->toDateString(),
                'document_number' => $original->document_number,
                'document_kind' => $original->document_kind,
                'document_type_code' => $original->document_type_code,
                'contact_id' => $original->contact_id,
                'counterparty_name' => $original->counterparty_name,
                'counterparty_vat' => $original->counterparty_vat,
                'taxable_amount' => Decimal::neg((string) $original->taxable_amount),
                'vat_rate' => $original->vat_rate,
                'vat_amount' => Decimal::neg((string) $original->vat_amount),
                'vat_nature' => $original->vat_nature,
                'gross_amount' => Decimal::neg((string) $original->gross_amount),
                'deduction_percentage' => $original->deduction_percentage,
                'deductible_vat' => Decimal::neg((string) $original->deductible_vat),
                'non_deductible_vat' => Decimal::neg((string) $original->non_deductible_vat),
                'non_deductible_reason' => $original->non_deductible_reason,
                'source_type' => 'vat_register_entry',
                'source_id' => $original->id,
                'notes' => $reason ?? 'Reversal of protocol '.$original->protocol_number,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Deletes a line that is not printed yet; the protocol number is not reused. A printed line cannot be deleted.
     */
    public function delete(string $tenantId, string $entryId): void
    {
        $this->findEntry($tenantId, $entryId)->delete();
    }

    public function findEntry(string $tenantId, string $id): VatRegisterEntry
    {
        return VatRegisterEntry::query()->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw ItalianVatException::notFound('entry_not_found');
    }

    /**
     * @return Collection<int, VatRegisterEntry>
     */
    public function entries(string $tenantId, string $sectionId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $section = $this->sections->find($tenantId, $sectionId);

        return VatRegisterEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('section_id', $section->id)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('year')
            ->orderBy('protocol_number')
            ->get();
    }

    /**
     * The register of a sectional with the totals per VAT rate and per nature (operations without VAT).
     *
     * @return array{section: VatSection, entries: Collection<int, VatRegisterEntry>, by_rate: list<array<string, mixed>>, by_nature: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function summary(string $tenantId, string $sectionId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $section = $this->sections->find($tenantId, $sectionId);
        $entries = $this->entries($tenantId, $sectionId, $from, $to);
        $byRate = [];
        $byNature = [];
        $totals = ['taxable' => '0', 'vat' => '0', 'deductible_vat' => '0', 'non_deductible_vat' => '0', 'gross' => '0'];

        foreach ($entries as $entry) {
            $totals['taxable'] = Decimal::add($totals['taxable'], (string) $entry->taxable_amount);
            $totals['vat'] = Decimal::add($totals['vat'], (string) $entry->vat_amount);
            $totals['deductible_vat'] = Decimal::add($totals['deductible_vat'], (string) $entry->deductible_vat);
            $totals['non_deductible_vat'] = Decimal::add($totals['non_deductible_vat'], (string) $entry->non_deductible_vat);
            $totals['gross'] = Decimal::add($totals['gross'], (string) $entry->gross_amount);

            if ($entry->vat_nature !== null) {
                $byNature[$entry->vat_nature] = $this->accumulate($byNature[$entry->vat_nature] ?? ['nature' => $entry->vat_nature], $entry);
            } else {
                $rate = Decimal::round2((string) $entry->vat_rate);
                $byRate[$rate] = $this->accumulate($byRate[$rate] ?? ['rate' => $rate], $entry);
            }
        }

        ksort($byRate);
        ksort($byNature);

        return [
            'section' => $section,
            'entries' => $entries,
            'by_rate' => array_values($byRate),
            'by_nature' => array_values($byNature),
            'totals' => array_map(Decimal::round2(...), $totals),
        ];
    }

    /**
     * The definitive print (stampa definitiva) of a sectional up to a date: assigns the page numbers and the virtual stamp,
     * fixes the content hash and locks every printed line. The protocol numbers of the print must be contiguous with the
     * previous print: a gap would mean a line was deleted or registered out of sequence.
     */
    public function definitivePrint(string $tenantId, ?string $userId, string $sectionId, int $year, string $periodTo): VatRegisterPrint
    {
        $to = CarbonImmutable::parse($periodTo)->startOfDay();

        if ($to->isFuture() || $to->year !== $year) {
            throw ItalianVatException::invalid('print_period_invalid');
        }

        return DB::transaction(function () use ($tenantId, $userId, $sectionId, $year, $to): VatRegisterPrint {
            $section = $this->sections->find($tenantId, $sectionId);
            $this->lockSection($section);

            $rows = $this->unprintedRows($tenantId, $section, $year, $to);

            if ($rows->isEmpty()) {
                throw ItalianVatException::invalid('print_nothing_to_print');
            }

            $previous = VatRegisterPrint::query()->where('tenant_id', $tenantId)->where('section_id', $section->id)->where('year', $year)
                ->orderByDesc('last_protocol')->first();
            $expected = ($previous->last_protocol ?? 0) + 1;

            foreach ($rows as $row) {
                if ((int) $row->protocol_number !== $expected) {
                    throw ItalianVatException::invalid('print_protocol_gap', ['expected' => $expected, 'found' => (int) $row->protocol_number]);
                }

                $expected++;
            }

            $lines = $rows->map(fn (VatRegisterEntry|ThirdPartyGoodsMovement $row): array => $this->lineOf($row))->values()->all();
            $pageFrom = ($previous->page_to ?? 0) + 1;
            $pageTo = $pageFrom + max(0, (int) ceil(count($lines) / self::ENTRIES_PER_PAGE) - 1);

            $print = VatRegisterPrint::create([
                'tenant_id' => $tenantId,
                'section_id' => $section->id,
                'year' => $year,
                'period_from' => min(array_column($lines, 'date')),
                'period_to' => $to->toDateString(),
                'first_protocol' => (int) $lines[0]['protocol'],
                'last_protocol' => (int) $lines[array_key_last($lines)]['protocol'],
                'entries_count' => count($lines),
                'page_from' => $pageFrom,
                'page_to' => $pageTo,
                'total_taxable' => $this->sum($lines, 'taxable'),
                'total_vat' => $this->sum($lines, 'vat'),
                'total_deductible_vat' => $this->sum($lines, 'deductible_vat'),
                'content_hash' => $this->hashOf($lines),
                'stamp_reference' => sprintf('%s/%d/%d-%d', $section->code, $year, $pageFrom, $pageTo),
                'printed_at' => now(),
                'printed_by' => $userId,
            ]);

            // A mass update does not go through the model guard, which only protects lines that are already printed.
            $this->attachPrint($section, $rows->pluck('id')->all(), (string) $print->id);

            return $print;
        });
    }

    /**
     * Checks that the lines of a print still match the hash recorded at printing time.
     *
     * @return array{intact: bool, expected_hash: string, actual_hash: string, expected_count: int, actual_count: int}
     */
    public function verifyPrint(string $tenantId, string $printId): array
    {
        $print = $this->findPrint($tenantId, $printId);
        $section = $this->sections->find($tenantId, $print->section_id);
        $rows = $this->printedRows($tenantId, $section, (string) $print->id, true);
        $lines = $rows->map(fn (VatRegisterEntry|ThirdPartyGoodsMovement $row): array => $this->lineOf($row))->values()->all();
        $actual = $this->hashOf($lines);

        return [
            'intact' => $actual === $print->content_hash && count($lines) === $print->entries_count && $rows->every(fn (VatRegisterEntry|ThirdPartyGoodsMovement $row): bool => ! $row->trashed()),
            'expected_hash' => $print->content_hash,
            'actual_hash' => $actual,
            'expected_count' => $print->entries_count,
            'actual_count' => count($lines),
        ];
    }

    public function findPrint(string $tenantId, string $id): VatRegisterPrint
    {
        return VatRegisterPrint::query()->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw ItalianVatException::notFound('print_not_found');
    }

    /**
     * @return Collection<int, VatRegisterPrint>
     */
    public function prints(string $tenantId, ?string $sectionId = null, ?int $year = null): Collection
    {
        return VatRegisterPrint::query()
            ->where('tenant_id', $tenantId)
            ->when($sectionId !== null, fn ($query) => $query->where('section_id', $sectionId))
            ->when($year !== null, fn ($query) => $query->where('year', $year))
            ->orderByDesc('year')
            ->orderBy('page_from')
            ->get();
    }

    /**
     * The PDF of a definitive print: every page is numbered with the stamp reference and the year, as the art. 39 requires.
     *
     * @return array{file_name: string, content: string}
     */
    public function printPdf(string $tenantId, string $printId): array
    {
        $print = $this->findPrint($tenantId, $printId);
        $section = $this->sections->find($tenantId, $print->section_id);
        $rows = $this->printedRows($tenantId, $section, (string) $print->id, false);
        $tenant = Tenant::query()->findOrFail($tenantId);

        return [
            'file_name' => sprintf('registro-iva-%s-%d-%s.pdf', strtolower($section->code), $print->year, $print->page_from),
            'content' => $this->pdfGenerator->render('italian-vat::register-print', [
                'tenant' => $tenant,
                'section' => $section,
                'print' => $print,
                'pages' => array_chunk($rows->map(fn (VatRegisterEntry|ThirdPartyGoodsMovement $row): array => $this->lineOf($row))->values()->all(), self::ENTRIES_PER_PAGE),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function amounts(string $tenantId, VatSection $section, CarbonImmutable $entryDate, string $kind, array $data): array
    {
        if (! in_array($kind, VatSectionService::DOCUMENT_KINDS, true)) {
            throw ItalianVatException::invalid('document_kind_invalid', ['kind' => $kind]);
        }

        $taxable = Decimal::round2((string) $data['taxable_amount']);
        $rate = Decimal::round2((string) ($data['vat_rate'] ?? '0'));
        $expectedVat = Decimal::round2(Decimal::div(Decimal::mul($taxable, $rate), '100'));
        $vat = isset($data['vat_amount']) ? Decimal::round2((string) $data['vat_amount']) : $expectedVat;
        $nature = $data['vat_nature'] ?? null;

        if (Decimal::compare(Decimal::abs(Decimal::sub($vat, $expectedVat)), '0.05') > 0) {
            throw ItalianVatException::invalid('vat_amount_mismatch', ['expected' => $expectedVat, 'given' => $vat]);
        }

        if (Decimal::isZero($vat) && Decimal::isZero($rate)) {
            if ($nature === null) {
                throw ItalianVatException::invalid('vat_nature_required');
            }

            if (! in_array($nature, self::NATURES, true)) {
                throw ItalianVatException::invalid('vat_nature_invalid', ['nature' => (string) $nature]);
            }
        } else {
            $nature = null;
        }

        $sign = $kind === 'credit_note' ? '-1' : '1';
        $percentage = '100';
        $reason = $data['non_deductible_reason'] ?? null;

        if ($section->register_type === VatSectionService::PURCHASES) {
            if (isset($data['deduction_percentage'])) {
                $percentage = Decimal::round2((string) $data['deduction_percentage']);
                $reason ??= 'other';
            } elseif ($data['apply_pro_rata'] ?? false) {
                $percentage = (string) $this->proRata->percentageFor($tenantId, $entryDate->year);
                $reason ??= 'pro_rata';
            }

            if (Decimal::compare($percentage, '0') < 0 || Decimal::compare($percentage, '100') > 0) {
                throw ItalianVatException::invalid('deduction_percentage_invalid');
            }
        }

        $split = $this->proRata->split($vat, $percentage);

        if ($section->register_type !== VatSectionService::PURCHASES || (Decimal::compare($percentage, '100') === 0 && $reason !== 'pro_rata')) {
            $reason = null;
        }

        $signed = static fn (string $value): string => Decimal::round2(Decimal::mul($value, $sign));

        return [
            'taxable_amount' => $signed($taxable),
            'vat_rate' => $rate,
            'vat_amount' => $signed($vat),
            'vat_nature' => $nature,
            'gross_amount' => $signed(Decimal::add($taxable, $vat)),
            'deduction_percentage' => $percentage,
            'deductible_vat' => $section->register_type === VatSectionService::PURCHASES ? $signed($split['deductible']) : '0.00',
            'non_deductible_vat' => $section->register_type === VatSectionService::PURCHASES ? $signed($split['non_deductible']) : '0.00',
            'non_deductible_reason' => $reason,
        ];
    }

    /**
     * Entries cannot be added to a period that a definitive print already closed.
     */
    public function assertPeriodOpen(VatSection $section, CarbonImmutable $date): void
    {
        $lockedThrough = VatRegisterPrint::query()
            ->where('section_id', $section->id)
            ->where('year', $date->year)
            ->max('period_to');

        if ($lockedThrough !== null && $date->lte(CarbonImmutable::parse((string) $lockedThrough))) {
            throw ItalianVatException::locked('period_locked', ['until' => CarbonImmutable::parse((string) $lockedThrough)->toDateString()]);
        }
    }

    /**
     * Serialises the numbering of a sectional: concurrent writers wait on the row of the section.
     */
    public function lockSection(VatSection $section): void
    {
        VatSection::query()->whereKey($section->id)->lockForUpdate()->first();
    }

    /**
     * The next progressive protocol number of a sectional in a year. Deleted lines keep their number: it is never reused.
     */
    public function nextProtocol(VatSection $section, int $year): int
    {
        $query = $section->register_type === VatSectionService::GOODS ? ThirdPartyGoodsMovement::query() : VatRegisterEntry::query();

        return ((int) $query->withTrashed()->where('section_id', $section->id)->where('year', $year)->max('protocol_number')) + 1;
    }

    /**
     * The lines of a sectional and year not covered by a print yet, up to a date, in protocol order.
     *
     * @return SupportCollection<int, VatRegisterEntry>|SupportCollection<int, ThirdPartyGoodsMovement>
     */
    private function unprintedRows(string $tenantId, VatSection $section, int $year, CarbonImmutable $to): SupportCollection
    {
        if ($section->register_type === VatSectionService::GOODS) {
            return ThirdPartyGoodsMovement::query()
                ->where('tenant_id', $tenantId)->where('section_id', $section->id)->where('year', $year)->whereNull('print_id')
                ->whereDate('movement_date', '<=', $to->toDateString())
                ->orderBy('protocol_number')
                ->get()
                ->collect();
        }

        return VatRegisterEntry::query()
            ->where('tenant_id', $tenantId)->where('section_id', $section->id)->where('year', $year)->whereNull('print_id')
            ->whereDate('entry_date', '<=', $to->toDateString())
            ->orderBy('protocol_number')
            ->get()
            ->collect();
    }

    /**
     * The lines covered by a print, in protocol order.
     *
     * @return SupportCollection<int, VatRegisterEntry>|SupportCollection<int, ThirdPartyGoodsMovement>
     */
    private function printedRows(string $tenantId, VatSection $section, string $printId, bool $withTrashed): SupportCollection
    {
        if ($section->register_type === VatSectionService::GOODS) {
            return ThirdPartyGoodsMovement::query()
                ->when($withTrashed, fn ($query) => $query->withTrashed())
                ->where('tenant_id', $tenantId)->where('print_id', $printId)
                ->orderBy('protocol_number')
                ->get()
                ->collect();
        }

        return VatRegisterEntry::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->where('tenant_id', $tenantId)->where('print_id', $printId)
            ->orderBy('protocol_number')
            ->get()
            ->collect();
    }

    /**
     * Marks lines as printed. A mass update does not go through the model guard, which only protects lines already printed.
     *
     * @param  list<string>  $ids
     */
    private function attachPrint(VatSection $section, array $ids, string $printId): void
    {
        $query = $section->register_type === VatSectionService::GOODS ? ThirdPartyGoodsMovement::query() : VatRegisterEntry::query();
        $query->whereIn('id', $ids)->update(['print_id' => $printId]);
    }

    /**
     * The canonical line of a register row, the base of the totals and of the content hash.
     *
     * @return array<string, string|int>
     */
    private function lineOf(VatRegisterEntry|ThirdPartyGoodsMovement $row): array
    {
        if ($row instanceof ThirdPartyGoodsMovement) {
            return [
                'protocol' => $row->protocol_number,
                'date' => $row->movement_date->toDateString(),
                'document_number' => (string) $row->document_number,
                'description' => $row->movement_type.' '.$row->description,
                'taxable' => Decimal::round2((string) $row->value),
                'rate' => '0.00',
                'vat' => '0.00',
                'deductible_vat' => '0.00',
                'quantity' => (string) $row->quantity,
            ];
        }

        return [
            'protocol' => $row->protocol_number,
            'date' => $row->entry_date->toDateString(),
            'document_number' => (string) $row->document_number,
            'description' => (string) ($row->counterparty_name ?? $row->document_kind),
            'taxable' => (string) $row->taxable_amount,
            'rate' => (string) $row->vat_rate,
            'vat' => (string) $row->vat_amount,
            'deductible_vat' => (string) $row->deductible_vat,
            'nature' => (string) $row->vat_nature,
        ];
    }

    /**
     * @param  list<array<string, string|int>>  $lines
     */
    private function hashOf(array $lines): string
    {
        return hash('sha256', (string) json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  list<array<string, string|int>>  $lines
     */
    private function sum(array $lines, string $key): string
    {
        $total = '0';

        foreach ($lines as $line) {
            $total = Decimal::add($total, (string) $line[$key]);
        }

        return Decimal::round2($total);
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function accumulate(array $bucket, VatRegisterEntry $entry): array
    {
        $bucket += ['count' => 0, 'taxable' => '0', 'vat' => '0', 'deductible_vat' => '0', 'non_deductible_vat' => '0'];
        $bucket['count']++;
        $bucket['taxable'] = Decimal::add((string) $bucket['taxable'], (string) $entry->taxable_amount);
        $bucket['vat'] = Decimal::add((string) $bucket['vat'], (string) $entry->vat_amount);
        $bucket['deductible_vat'] = Decimal::add((string) $bucket['deductible_vat'], (string) $entry->deductible_vat);
        $bucket['non_deductible_vat'] = Decimal::add((string) $bucket['non_deductible_vat'], (string) $entry->non_deductible_vat);

        foreach (['taxable', 'vat', 'deductible_vat', 'non_deductible_vat'] as $key) {
            $bucket[$key] = Decimal::round2((string) $bucket[$key]);
        }

        return $bucket;
    }
}

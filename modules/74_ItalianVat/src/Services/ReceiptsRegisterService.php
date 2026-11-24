<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\VatRegisterEntry;
use Modules\ItalianVat\Models\VatSection;
use Modules\ItalianVat\Models\VatVentilation;

/**
 * Register of the daily receipts (corrispettivi, art. 24 DPR 633/1972). A retailer that sells goods at several rates without
 * recording each sale by rate splits the gross takings with the ventilation (ventilazione): in proportion to the purchases of
 * goods for resale of the period at each rate. Takings are gross of VAT, so the taxable amount is scorporated from each part.
 */
final class ReceiptsRegisterService
{
    public function __construct(
        private readonly VatRegisterService $registers,
        private readonly VatSectionService $sections,
    ) {}

    /**
     * Splits the gross takings in proportion to the gross purchases at each rate. The last line takes the rounding remainder,
     * so the parts always add up to the takings.
     *
     * @param  list<array{rate: int|float|string, amount: int|float|string, nature?: string|null}>  $purchaseBasis
     * @return array{lines: list<array{rate: string, nature: string|null, share: string, gross: string, taxable: string, vat: string}>, total_taxable: string, total_vat: string}
     */
    public function ventilate(string $grossTakings, array $purchaseBasis): array
    {
        if (! Decimal::isPositive($grossTakings)) {
            throw ItalianVatException::invalid('takings_must_be_positive');
        }

        $base = '0';

        foreach ($purchaseBasis as $item) {
            if (Decimal::isNegative((string) $item['amount'])) {
                throw ItalianVatException::invalid('purchase_basis_negative');
            }

            $base = Decimal::add($base, (string) $item['amount']);
        }

        if (! Decimal::isPositive($base)) {
            throw ItalianVatException::invalid('purchase_basis_empty');
        }

        $lines = [];
        $allocated = '0.00';
        $totalTaxable = '0';
        $totalVat = '0';
        $last = count($purchaseBasis) - 1;

        foreach ($purchaseBasis as $index => $item) {
            $rate = Decimal::round2((string) $item['rate']);
            $nature = $item['nature'] ?? null;

            if (Decimal::isZero($rate) && $nature === null) {
                throw ItalianVatException::invalid('vat_nature_required');
            }

            $share = Decimal::div((string) $item['amount'], $base);
            $gross = $index === $last
                ? Decimal::round2(Decimal::sub($grossTakings, $allocated))
                : Decimal::round2(Decimal::mul($grossTakings, $share));
            $allocated = Decimal::round2(Decimal::add($allocated, $gross));
            $taxable = Decimal::round2(Decimal::div(Decimal::mul($gross, '100'), Decimal::add('100', $rate)));
            $vat = Decimal::round2(Decimal::sub($gross, $taxable));
            $totalTaxable = Decimal::add($totalTaxable, $taxable);
            $totalVat = Decimal::add($totalVat, $vat);

            $lines[] = ['rate' => $rate, 'nature' => Decimal::isZero($rate) ? $nature : null, 'share' => Decimal::round2(Decimal::mul($share, '100')), 'gross' => $gross, 'taxable' => $taxable, 'vat' => $vat];
        }

        return ['lines' => $lines, 'total_taxable' => Decimal::round2($totalTaxable), 'total_vat' => Decimal::round2($totalVat)];
    }

    /**
     * Registers the ventilated takings of a day: one line of the register per rate, all in the same transaction.
     *
     * @param  array<string, mixed>  $data  entry_date, gross_takings, purchase_basis, section_id (optional)
     * @return array{ventilation: VatVentilation, entries: list<VatRegisterEntry>}
     */
    public function registerVentilated(string $tenantId, ?string $userId, array $data): array
    {
        $result = $this->ventilate((string) $data['gross_takings'], $data['purchase_basis']);
        $section = $this->receiptsSection($tenantId, $data['section_id'] ?? null);
        $date = CarbonImmutable::parse((string) $data['entry_date'])->toDateString();

        return DB::transaction(function () use ($tenantId, $userId, $data, $result, $section, $date): array {
            $ventilation = VatVentilation::create([
                'tenant_id' => $tenantId,
                'entry_date' => $date,
                'gross_takings' => Decimal::round2((string) $data['gross_takings']),
                'purchase_basis' => $data['purchase_basis'],
                'breakdown' => $result['lines'],
                'total_taxable' => $result['total_taxable'],
                'total_vat' => $result['total_vat'],
                'registered_at' => now(),
                'created_by' => $userId,
            ]);

            $entries = [];

            foreach ($result['lines'] as $line) {
                $entries[] = $this->registers->record($tenantId, $userId, [
                    'section_id' => $section->id,
                    'entry_date' => $date,
                    'document_number' => 'COR-'.str_replace('-', '', $date),
                    'taxable_amount' => $line['taxable'],
                    'vat_rate' => $line['rate'],
                    'vat_amount' => $line['vat'],
                    'vat_nature' => $line['nature'],
                    'source_type' => 'vat_ventilation',
                    'source_id' => $ventilation->id,
                    'notes' => 'Ventilation '.$line['share'].'%',
                ]);
            }

            return ['ventilation' => $ventilation, 'entries' => $entries];
        });
    }

    /**
     * Registers the takings of a day already split by rate (cash registers that report them by rate).
     *
     * @param  array<string, mixed>  $data  entry_date, lines [{rate, gross, nature?}], section_id (optional)
     * @return list<VatRegisterEntry>
     */
    public function registerByRate(string $tenantId, ?string $userId, array $data): array
    {
        $section = $this->receiptsSection($tenantId, $data['section_id'] ?? null);
        $date = CarbonImmutable::parse((string) $data['entry_date'])->toDateString();

        return DB::transaction(function () use ($tenantId, $userId, $data, $section, $date): array {
            $entries = [];

            foreach ($data['lines'] as $line) {
                $rate = Decimal::round2((string) $line['rate']);
                $gross = Decimal::round2((string) $line['gross']);
                $taxable = Decimal::round2(Decimal::div(Decimal::mul($gross, '100'), Decimal::add('100', $rate)));

                $entries[] = $this->registers->record($tenantId, $userId, [
                    'section_id' => $section->id,
                    'entry_date' => $date,
                    'document_number' => 'COR-'.str_replace('-', '', $date),
                    'taxable_amount' => $taxable,
                    'vat_rate' => $rate,
                    'vat_amount' => Decimal::round2(Decimal::sub($gross, $taxable)),
                    'vat_nature' => $line['nature'] ?? null,
                ]);
            }

            return $entries;
        });
    }

    private function receiptsSection(string $tenantId, mixed $sectionId): VatSection
    {
        if (is_string($sectionId) && $sectionId !== '') {
            $section = $this->sections->find($tenantId, $sectionId);

            if ($section->register_type !== VatSectionService::RECEIPTS) {
                throw ItalianVatException::invalid('section_not_receipts');
            }

            return $section;
        }

        return $this->sections->list($tenantId, VatSectionService::RECEIPTS)->first()
            ?? throw ItalianVatException::notFound('section_not_found');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Lex398Entry;
use Modules\Associations\Support\CsvFile;
use Modules\Associations\Support\Money;

/**
 * The regime of Law 398/1991 for a company that opted for it: the simplified register of commercial income, filled
 * from the posted invoices or by hand, and the monitoring of the ceiling of the year. The ceiling, the warning share and
 * the flat-rate deduction are legal parameters of the year; without them the monitoring reports "not configured".
 */
final class Lex398Service
{
    public function __construct(private readonly LegalParameterService $parameters) {}

    public function assertEnabled(): void
    {
        if (! AssociationSetting::current()->lex398_enabled) {
            throw new AssociationException(__('association_finance.lex398_disabled'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, ?string $userId): Lex398Entry
    {
        $this->assertEnabled();
        $taxable = Money::normalize((string) $data['taxable_amount']);
        $vat = Money::normalize((string) ($data['vat_amount'] ?? '0'));

        return Lex398Entry::create([...$data, 'taxable_amount' => $taxable, 'vat_amount' => $vat, 'total_amount' => Money::add($taxable, $vat), 'source_type' => 'manual', 'recorded_by' => $userId]);
    }

    /**
     * Copies the posted invoices of the year that the association issued through the invoicing module and are not yet
     * in the register. Internal receipts kept outside the electronic invoicing system are not commercial income here.
     *
     * @return int entries added
     */
    public function syncInvoices(int $year, ?string $userId): int
    {
        $this->assertEnabled();
        $added = 0;
        $known = Lex398Entry::query()->where('source_type', 'invoice')->pluck('source_id')->map(static fn (mixed $id): string => (string) $id)->all();

        $invoices = Invoice::query()->with('contact')
            ->whereYear('date', $year)
            ->whereNotNull('posted_at')
            ->where('sdi_excluded', false)
            ->whereIn('invoice_type', ['standard', 'sdi_electronic', 'credit_note'])
            ->whereNotIn('id', $known)
            ->orderBy('date')
            ->get();

        foreach ($invoices as $invoice) {
            $sign = $invoice->invoice_type === 'credit_note' ? '-1' : '1';
            $taxable = bcmul(Money::normalize((string) $invoice->total_net), $sign, 2);
            $vat = bcmul(Money::normalize((string) $invoice->total_tax), $sign, 2);

            Lex398Entry::create([
                'entry_date' => CarbonImmutable::parse($invoice->date)->toDateString(),
                'document_number' => (string) $invoice->invoice_number,
                'counterparty' => mb_substr(trim(($invoice->contact?->company_name ?: trim("{$invoice->contact?->first_name} {$invoice->contact?->last_name}"))), 0, 255) ?: null,
                'description' => null,
                'taxable_amount' => $taxable,
                'vat_rate' => null,
                'vat_amount' => $vat,
                'total_amount' => Money::add($taxable, $vat),
                'source_type' => 'invoice',
                'source_id' => $invoice->id,
                'recorded_by' => $userId,
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * @return array{enabled: bool, year: int, commercial_income: string, ceiling: ?string, warning_percent: ?string, used_percent: ?string, remaining: ?string, status: string, source: ?string}
     */
    public function monitor(int $year): array
    {
        $income = $this->entries($year)->reduce(static fn (string $total, Lex398Entry $entry): string => Money::add($total, Money::normalize((string) $entry->taxable_amount)), '0.00');
        $ceiling = $this->parameters->value(LegalParameterService::Lex398Ceiling, $year);
        $warning = $this->parameters->value(LegalParameterService::Lex398WarningPercent, $year);
        $used = $ceiling === null || Money::compare(Money::normalize($ceiling), '0') <= 0 ? null : bcdiv(bcmul($income, '100', 4), Money::normalize($ceiling), 2);

        $status = match (true) {
            $ceiling === null => 'not_configured',
            Money::compare($income, Money::normalize($ceiling)) > 0 => 'exceeded',
            $warning !== null && $used !== null && bccomp($used, $warning, 2) >= 0 => 'warning',
            default => 'within_ceiling',
        };

        return [
            'enabled' => (bool) AssociationSetting::current()->lex398_enabled,
            'year' => $year,
            'commercial_income' => $income,
            'ceiling' => $ceiling === null ? null : Money::normalize($ceiling),
            'warning_percent' => $warning,
            'used_percent' => $used,
            'remaining' => $ceiling === null ? null : Money::sub(Money::normalize($ceiling), $income),
            'status' => $status,
            'source' => $this->parameters->find(LegalParameterService::Lex398Ceiling, $year)?->source_reference,
        ];
    }

    /**
     * The register of the year with totals by rate and, when the flat-rate deduction is configured, the VAT due.
     *
     * @return array{year: int, entries: Collection<int, Lex398Entry>, by_rate: list<array{vat_rate: ?string, taxable: string, vat: string}>, totals: array{taxable: string, vat: string, flat_deduction_percent: ?string, flat_deduction: ?string, vat_due: ?string}}
     */
    public function register(int $year): array
    {
        $entries = $this->entries($year);
        $byRate = [];

        foreach ($entries->groupBy(static fn (Lex398Entry $entry): string => $entry->vat_rate === null ? 'none' : (string) $entry->vat_rate) as $rate => $rows) {
            $byRate[] = [
                'vat_rate' => $rate === 'none' ? null : (string) $rate,
                'taxable' => $rows->reduce(static fn (string $total, Lex398Entry $entry): string => Money::add($total, Money::normalize((string) $entry->taxable_amount)), '0.00'),
                'vat' => $rows->reduce(static fn (string $total, Lex398Entry $entry): string => Money::add($total, Money::normalize((string) $entry->vat_amount)), '0.00'),
            ];
        }

        $taxable = collect($byRate)->reduce(static fn (string $total, array $row): string => Money::add($total, $row['taxable']), '0.00');
        $vat = collect($byRate)->reduce(static fn (string $total, array $row): string => Money::add($total, $row['vat']), '0.00');
        $percent = $this->parameters->value(LegalParameterService::Lex398VatFlatDeductionPercent, $year);
        $deduction = $percent === null ? null : Money::percentage($vat, $percent);

        return [
            'year' => $year,
            'entries' => $entries,
            'by_rate' => $byRate,
            'totals' => ['taxable' => $taxable, 'vat' => $vat, 'flat_deduction_percent' => $percent, 'flat_deduction' => $deduction, 'vat_due' => $deduction === null ? null : Money::sub($vat, $deduction)],
        ];
    }

    public function csv(int $year): string
    {
        $rows = $this->entries($year)->map(static fn (Lex398Entry $entry): array => [
            $entry->entry_date->toDateString(), $entry->document_number, $entry->counterparty, $entry->description,
            Money::normalize((string) $entry->taxable_amount), $entry->vat_rate, Money::normalize((string) $entry->vat_amount), Money::normalize((string) $entry->total_amount), $entry->source_type,
        ]);

        return CsvFile::write(['date', 'document', 'counterparty', 'description', 'taxable', 'vat_rate', 'vat', 'total', 'source'], $rows);
    }

    /**
     * @return Collection<int, Lex398Entry>
     */
    private function entries(int $year): Collection
    {
        return Lex398Entry::query()->whereYear('entry_date', $year)->orderBy('entry_date')->orderBy('document_number')->get();
    }
}

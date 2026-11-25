<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\CorporateConventions\Domain\Enums\SplitBillingStatus;
use Modules\CorporateConventions\Domain\Support\Dec;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Models\ConventionSplitBilling;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Services\InvoicingService;

/**
 * Consolidates the employer quotas of a convention into one B2B invoice for the partner (the invoicing module issues it as FatturaPA
 * in Italy and with the Swiss QR-bill in Switzerland), with a beneficiary schedule attached that is either nominal or anonymized
 * (GDPR / nLPD) according to the convention.
 */
final class SplitBillingConsolidationService
{
    public function __construct(private readonly InvoicingService $invoicing) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(CorporatePartnerConvention $convention, CarbonImmutable $from, CarbonImmutable $to, ?bool $anonymize = null): array
    {
        $this->assertPeriod($from, $to);
        $anonymize ??= $convention->split_billing_anonymized;
        $rows = $this->billable($convention, $from, $to);

        return [
            'convention_id' => $convention->id,
            'convention_name' => $convention->name,
            'partner_company_name' => $convention->partner_company_name,
            'currency' => $convention->currency,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'anonymized' => $anonymize,
            'rows_count' => $rows->count(),
            'total_employer_quota' => $this->total($rows),
            'schedule' => $this->schedule($rows, $anonymize),
        ];
    }

    /**
     * @return array{invoice: SalesInvoice, attachment_path: string, rows_count: int, total_employer_quota: string}
     */
    public function consolidate(CorporatePartnerConvention $convention, CarbonImmutable $from, CarbonImmutable $to, ?bool $anonymize, ?string $userId): array
    {
        $this->assertPeriod($from, $to);
        $anonymize ??= $convention->split_billing_anonymized;

        if ($convention->partner_contact_id === null) {
            throw new ConventionException('consolidation_partner_without_customer', 422);
        }

        return DB::transaction(function () use ($convention, $from, $to, $anonymize, $userId): array {
            $rows = $this->billable($convention, $from, $to, true);

            if ($rows->isEmpty()) {
                throw new ConventionException('consolidation_nothing_to_bill', 422);
            }

            $total = $this->total($rows);
            $rate = (string) (config('corporate_conventions.invoice_vat_rate')[$convention->country_code] ?? '0');
            $period = $from->toDateString().' - '.$to->toDateString();

            $invoice = $this->invoicing->createInvoice([
                'tenant_id' => $convention->tenant_id,
                'contact_id' => $convention->partner_contact_id,
                'invoice_type' => 'standard',
                'currency' => $convention->currency,
                'jurisdiction' => $convention->country_code === 'CH' ? 'CH' : 'IT',
                'notes' => (string) __('corporate_conventions.invoice.notes', ['convention' => $convention->name, 'period' => $period]),
                'created_by' => $userId,
            ], $this->items($convention, $rows, $anonymize, $rate, $period));

            $path = $this->storeSchedule($convention, $invoice, $this->schedule($rows, $anonymize));

            foreach ($rows as $row) {
                $row->markInvoiced($invoice->id);
                $row->updated_by = $userId;
                $row->save();
            }

            return ['invoice' => $invoice, 'attachment_path' => $path, 'rows_count' => $rows->count(), 'total_employer_quota' => $total];
        });
    }

    /**
     * Pending quotas by convention, for the reconciliation screen.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(?string $conventionId = null): array
    {
        return ConventionSplitBilling::query()
            ->where('billing_status', SplitBillingStatus::PendingConsolidation->value)
            ->where('employer_subsidy_amount', '>', 0)
            ->when($conventionId, fn (Builder $query, string $id) => $query->where('convention_id', $id))
            ->with('convention:id,name,partner_company_name,currency,billing_cycle')
            ->get()
            ->groupBy('convention_id')
            ->map(function (Collection $rows): array {
                $convention = $rows->first()->convention;

                return [
                    'convention_id' => $convention->id,
                    'convention_name' => $convention->name,
                    'partner_company_name' => $convention->partner_company_name,
                    'billing_cycle' => $convention->billing_cycle->value,
                    'currency' => $convention->currency,
                    'rows_count' => $rows->count(),
                    'total_employer_quota' => $this->total($rows),
                    'oldest_transaction_date' => $rows->min(fn (ConventionSplitBilling $row): string => $row->transaction_date->toDateString()),
                    'newest_transaction_date' => $rows->max(fn (ConventionSplitBilling $row): string => $row->transaction_date->toDateString()),
                ];
            })->values()->all();
    }

    /**
     * Path of the beneficiary schedule attached to an invoice of this module.
     */
    public function attachmentPath(string $invoiceId): string
    {
        $row = ConventionSplitBilling::query()->where('b2b_consolidated_invoice_id', $invoiceId)->first();

        if ($row === null) {
            throw new ConventionException('consolidation_invoice_unknown', 404);
        }

        $path = $this->schedulePath((string) $row->tenant_id, $invoiceId);

        if (! Storage::disk((string) config('corporate_conventions.storage.disk'))->exists($path)) {
            throw new ConventionException('consolidation_attachment_missing', 404);
        }

        return $path;
    }

    /**
     * @return Collection<int, ConventionSplitBilling>
     */
    private function billable(CorporatePartnerConvention $convention, CarbonImmutable $from, CarbonImmutable $to, bool $lock = false): Collection
    {
        return ConventionSplitBilling::query()
            ->with('enrollment:id,full_name')
            ->where('convention_id', $convention->id)
            ->where('billing_status', SplitBillingStatus::PendingConsolidation->value)
            ->where('employer_subsidy_amount', '>', 0)
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('transaction_date')->orderBy('created_at')
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->get();
    }

    /**
     * @param  Collection<int, ConventionSplitBilling>  $rows
     */
    private function total(Collection $rows): string
    {
        return Dec::round($rows->reduce(fn (string $carry, ConventionSplitBilling $row): string => Dec::add($carry, $row->employer_subsidy_amount), '0'));
    }

    /**
     * @param  Collection<int, ConventionSplitBilling>  $rows
     * @return list<array<string, string>>
     */
    private function schedule(Collection $rows, bool $anonymize): array
    {
        return $rows->map(fn (ConventionSplitBilling $row): array => [
            'date' => $row->transaction_date->toDateString(),
            'beneficiary' => $anonymize ? $this->pseudonym($row->enrollment_id) : (string) ($row->enrollment->full_name ?? ''),
            'document' => $row->source_document_id,
            'gross' => (string) $row->total_gross_amount,
            'discount' => (string) $row->convention_discount_amount,
            'employer_quota' => (string) $row->employer_subsidy_amount,
            'employee_paid' => (string) $row->employee_paid_amount,
        ])->values()->all();
    }

    private function pseudonym(string $enrollmentId): string
    {
        return 'B-'.strtoupper(substr(hash('sha256', $enrollmentId), 0, 8));
    }

    /**
     * The quota is a gross amount (it is part of the retail price): the invoice shows it net of VAT with the rate of the country.
     *
     * @param  Collection<int, ConventionSplitBilling>  $rows
     * @return list<array<string, mixed>>
     */
    private function items(CorporatePartnerConvention $convention, Collection $rows, bool $anonymize, string $rate, string $period): array
    {
        $net = static fn (string $gross): string => Dec::round(Dec::div($gross, Dec::add('1', Dec::div($rate, '100'))), 2);

        if ($anonymize) {
            return [[
                'description' => (string) __('corporate_conventions.invoice.line_aggregated', ['convention' => $convention->name, 'period' => $period, 'count' => $rows->count()]),
                'quantity' => 1,
                'unit_price' => $net($this->total($rows)),
                'tax_rate' => (float) $rate,
            ]];
        }

        return $rows->map(fn (ConventionSplitBilling $row): array => [
            'description' => (string) __('corporate_conventions.invoice.line_nominal', ['convention' => $convention->name, 'date' => $row->transaction_date->toDateString(), 'beneficiary' => (string) ($row->enrollment->full_name ?? '')]),
            'quantity' => 1,
            'unit_price' => $net((string) $row->employer_subsidy_amount),
            'tax_rate' => (float) $rate,
        ])->values()->all();
    }

    /**
     * @param  list<array<string, string>>  $schedule
     */
    private function storeSchedule(CorporatePartnerConvention $convention, SalesInvoice $invoice, array $schedule): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['date', 'beneficiary', 'document', 'gross', 'discount', 'employer_quota', 'employee_paid'], ',', '"', '');

        foreach ($schedule as $line) {
            fputcsv($stream, array_values($line), ',', '"', '');
        }

        rewind($stream);
        $path = $this->schedulePath((string) $convention->tenant_id, (string) $invoice->id);
        Storage::disk((string) config('corporate_conventions.storage.disk'))->put($path, (string) stream_get_contents($stream));
        fclose($stream);

        return $path;
    }

    private function schedulePath(string $tenantId, string $invoiceId): string
    {
        return config('corporate_conventions.storage.folder').'/'.$tenantId.'/schedules/'.$invoiceId.'.csv';
    }

    private function assertPeriod(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($to->lt($from)) {
            throw new ConventionException('consolidation_period_invalid', 422);
        }
    }
}

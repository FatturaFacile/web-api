<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\SwissVat\Domain\Enums\BezugsteuerCategory;
use Modules\SwissVat\Domain\Enums\BezugsteuerStatus;
use Modules\SwissVat\Domain\Enums\SwissVatMethod;
use Modules\SwissVat\Exceptions\SwissVatException;
use Modules\SwissVat\Models\SwissVatBezugsteuerEntry;
use Modules\SwissVat\Models\SwissVatConfig;

/**
 * Automatic & manual handling of Swiss Acquisition Tax (Bezugsteuer / Impôt sur les acquisitions, Art. 45 LIVA).
 * Detects cross-border service, software/cloud, and consulting bills from foreign suppliers (country outside CH/LI).
 */
final class BezugsteuerDetectionService
{
    /** Switzerland and Liechtenstein form a unified Swiss VAT territory (Art. 3 LIVA). */
    private const array DOMESTIC_VAT_TERRITORY = ['CH', 'LI'];

    /** @var array<string, list<string>> */
    private const array CATEGORY_KEYWORDS = [
        BezugsteuerCategory::SoftwareCloud->value => ['software', 'saas', 'cloud', 'aws', 'azure', 'gcp', 'google cloud', 'microsoft', 'github', 'license', 'licenza', 'lizenz', 'hosting', 'subscription', 'adobe', 'slack', 'atlassian'],
        BezugsteuerCategory::Consulting->value => ['consulting', 'consulenza', 'beratung', 'conseil', 'advisory', 'legal', 'audit', 'strategy', 'treuhand'],
        BezugsteuerCategory::ItServices->value => ['it service', 'development', 'sviluppo', 'programmierung', 'maintenance', 'support', 'api'],
        BezugsteuerCategory::Marketing->value => ['marketing', 'ads', 'advertising', 'google ads', 'meta', 'linkedin', 'seo', 'pubblicità', 'werbung'],
        BezugsteuerCategory::Telecom->value => ['telecom', 'telephony', 'voip', 'sms', 'bandwidth', 'internet'],
        BezugsteuerCategory::Royalties->value => ['royalty', 'royalties', 'trademark', 'patent', 'copyright', 'brevetto'],
    ];

    public function __construct(
        private readonly SwissVatRateCatalogService $catalog,
    ) {}

    /**
     * @return Collection<int, SwissVatBezugsteuerEntry>
     */
    public function list(string $tenantId, ?string $status = null, ?string $from = null, ?string $to = null): Collection
    {
        return SwissVatBezugsteuerEntry::query()
            ->where('tenant_id', $tenantId)
            ->when($status !== null && $status !== '', static fn ($q) => $q->where('status', $status))
            ->when($from !== null && $from !== '', static fn ($q) => $q->whereDate('invoice_date', '>=', $from))
            ->when($to !== null && $to !== '', static fn ($q) => $q->whereDate('invoice_date', '<=', $to))
            ->orderByDesc('invoice_date')
            ->get();
    }

    /**
     * Scans passive_invoices and journal_lines in [from, to] for foreign supplier services subject to Bezugsteuer.
     *
     * @return Collection<int, SwissVatBezugsteuerEntry>
     */
    public function detectForPeriod(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?string $userId = null): Collection
    {
        $config = SwissVatConfig::query()->where('tenant_id', $tenantId)->first();
        $isEffective = ($config === null ? SwissVatMethod::Effective->value : $config->accounting_method) === SwissVatMethod::Effective->value;
        $epoch = $this->catalog->ratesForDate($to);
        $rate = $epoch['standard'];
        $afcBox = $epoch['boxes']['bezugsteuer'];

        $created = new Collection;

        if (Schema::hasTable('passive_invoices') && Schema::hasTable('contacts')) {
            $bills = DB::table('passive_invoices as pi')
                ->join('contacts as c', 'pi.supplier_id', '=', 'c.id')
                ->where('pi.tenant_id', $tenantId)
                ->whereNull('pi.deleted_at')
                ->whereDate('pi.date', '>=', $from->toDateString())
                ->whereDate('pi.date', '<=', $to->toDateString())
                ->get([
                    'pi.id',
                    'pi.supplier_id',
                    'pi.invoice_number',
                    'pi.date',
                    'pi.currency',
                    'pi.exchange_rate',
                    'pi.total_net',
                    'pi.total_tax',
                    'pi.total_amount',
                    'pi.document_type',
                    'c.country as supplier_country',
                    'c.company_name as supplier_name',
                    'c.vat_number as supplier_vat_number',
                ]);

            foreach ($bills as $bill) {
                $country = strtoupper(trim((string) ($bill->supplier_country ?? '')));
                if ($country === '' || in_array($country, self::DOMESTIC_VAT_TERRITORY, true)) {
                    continue;
                }

                // Skip bills that already charged Swiss VAT on the domestic invoice
                if (SwissVatDecimal::isPositive((string) ($bill->total_tax ?? '0'))) {
                    continue;
                }

                $exists = SwissVatBezugsteuerEntry::query()
                    ->where('tenant_id', $tenantId)
                    ->where('source_document_type', 'passive_invoice')
                    ->where('source_document_id', (string) $bill->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $origNet = SwissVatDecimal::isPositive((string) ($bill->total_net ?? '0'))
                    ? (string) $bill->total_net
                    : (string) ($bill->total_amount ?? '0');

                if (! SwissVatDecimal::isPositive($origNet)) {
                    continue;
                }

                $fx = $bill->exchange_rate !== null && SwissVatDecimal::isPositive((string) $bill->exchange_rate)
                    ? (string) $bill->exchange_rate
                    : '1';
                $netChf = SwissVatDecimal::round2(SwissVatDecimal::mul($origNet, $fx));
                $taxChf = SwissVatDecimal::round05(SwissVatDecimal::div(SwissVatDecimal::mul($netChf, $rate), '100'));
                $category = $this->inferCategory((string) ($bill->supplier_name ?? '').' '.(string) ($bill->invoice_number ?? ''));

                $entry = SwissVatBezugsteuerEntry::create([
                    'tenant_id' => $tenantId,
                    'source_document_type' => 'passive_invoice',
                    'source_document_id' => (string) $bill->id,
                    'supplier_id' => (string) $bill->supplier_id,
                    'supplier_name' => (string) ($bill->supplier_name ?: 'Foreign Supplier'),
                    'supplier_country' => $country,
                    'supplier_vat_number' => $bill->supplier_vat_number ? (string) $bill->supplier_vat_number : null,
                    'invoice_number' => (string) $bill->invoice_number,
                    'invoice_date' => substr((string) $bill->date, 0, 10),
                    'service_category' => $category,
                    'description' => sprintf('Foreign service acquisition (%s) - %s', $country, (string) $bill->invoice_number),
                    'currency' => strtoupper((string) ($bill->currency ?: 'CHF')),
                    'original_net_amount' => SwissVatDecimal::round2($origNet),
                    'exchange_rate' => $fx,
                    'net_amount_chf' => $netChf,
                    'vat_rate_percent' => $rate,
                    'tax_amount_chf' => $taxChf,
                    'deductible_input_tax_chf' => $isEffective ? $taxChf : '0.00',
                    'afc_box' => $afcBox,
                    'detection_source' => 'auto_detected',
                    'detection_reason' => "Foreign supplier ({$country}) without domestic Swiss MWST",
                    'status' => BezugsteuerStatus::Detected->value,
                    'created_by' => $userId,
                ]);

                $created->push($entry);
            }
        }

        // Also scan posted reverse-charge journal lines with foreign suppliers not yet registered
        if (Schema::hasTable('journal_lines') && Schema::hasTable('journal_entries')) {
            $lines = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
                ->leftJoin('contacts as c', 'jl.partner_id', '=', 'c.id')
                ->where('je.tenant_id', $tenantId)
                ->whereNull('je.deleted_at')
                ->whereNull('jl.deleted_at')
                ->where('je.status', 'posted')
                ->whereDate('je.posting_date', '>=', $from->toDateString())
                ->whereDate('je.posting_date', '<=', $to->toDateString())
                ->where('jl.vat_treatment', 'reverse_charge')
                ->get([
                    'je.id as entry_id',
                    'je.entry_number',
                    'je.document_number',
                    'je.posting_date',
                    'jl.partner_id',
                    'jl.vat_base_amount',
                    'jl.vat_rate_percent',
                    'jl.exchange_rate',
                    'jl.description',
                    'c.country as supplier_country',
                    'c.company_name as supplier_name',
                    'c.vat_number as supplier_vat_number',
                ]);

            foreach ($lines as $line) {
                $country = strtoupper(trim((string) ($line->supplier_country ?? 'DE')));
                if (in_array($country, self::DOMESTIC_VAT_TERRITORY, true)) {
                    continue;
                }

                $exists = SwissVatBezugsteuerEntry::query()
                    ->where('tenant_id', $tenantId)
                    ->where('source_document_type', 'journal_entry')
                    ->where('source_document_id', (string) $line->entry_id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $base = (string) ($line->vat_base_amount ?? '0');
                if (! SwissVatDecimal::isPositive($base)) {
                    continue;
                }

                $fx = $line->exchange_rate !== null && SwissVatDecimal::isPositive((string) $line->exchange_rate)
                    ? (string) $line->exchange_rate
                    : '1';
                $netChf = SwissVatDecimal::round2(SwissVatDecimal::mul($base, $fx));
                $lineRate = $line->vat_rate_percent !== null && SwissVatDecimal::isPositive((string) $line->vat_rate_percent)
                    ? SwissVatDecimal::round2((string) $line->vat_rate_percent)
                    : $rate;
                $taxChf = SwissVatDecimal::round05(SwissVatDecimal::div(SwissVatDecimal::mul($netChf, $lineRate), '100'));

                $entry = SwissVatBezugsteuerEntry::create([
                    'tenant_id' => $tenantId,
                    'source_document_type' => 'journal_entry',
                    'source_document_id' => (string) $line->entry_id,
                    'supplier_id' => $line->partner_id ? (string) $line->partner_id : null,
                    'supplier_name' => (string) ($line->supplier_name ?: 'Foreign Service Provider'),
                    'supplier_country' => $country,
                    'supplier_vat_number' => $line->supplier_vat_number ? (string) $line->supplier_vat_number : null,
                    'invoice_number' => (string) ($line->document_number ?: $line->entry_number),
                    'invoice_date' => substr((string) $line->posting_date, 0, 10),
                    'service_category' => $this->inferCategory((string) ($line->description ?? '')),
                    'description' => (string) ($line->description ?: "Reverse-charge service ({$line->entry_number})"),
                    'currency' => 'CHF',
                    'original_net_amount' => SwissVatDecimal::round2($base),
                    'exchange_rate' => $fx,
                    'net_amount_chf' => $netChf,
                    'vat_rate_percent' => $lineRate,
                    'tax_amount_chf' => $taxChf,
                    'deductible_input_tax_chf' => $isEffective ? $taxChf : '0.00',
                    'afc_box' => $afcBox,
                    'detection_source' => 'auto_detected',
                    'detection_reason' => 'Journal reverse-charge line from foreign supplier',
                    'status' => BezugsteuerStatus::Detected->value,
                    'created_by' => $userId,
                ]);

                $created->push($entry);
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createManual(string $tenantId, ?string $userId, array $data): SwissVatBezugsteuerEntry
    {
        $country = strtoupper(trim((string) $data['supplier_country']));
        if (in_array($country, self::DOMESTIC_VAT_TERRITORY, true)) {
            throw SwissVatException::invalid('bezugsteuer_domestic_supplier_forbidden', ['country' => $country]);
        }

        $invoiceDate = CarbonImmutable::parse((string) $data['invoice_date']);
        $epoch = $this->catalog->ratesForDate($invoiceDate);
        $rate = isset($data['vat_rate_percent']) ? SwissVatDecimal::round2((string) $data['vat_rate_percent']) : $epoch['standard'];
        $fx = isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : '1';
        $origNet = (string) $data['original_net_amount'];
        $netChf = SwissVatDecimal::round2(SwissVatDecimal::mul($origNet, $fx));
        $taxChf = SwissVatDecimal::round05(SwissVatDecimal::div(SwissVatDecimal::mul($netChf, $rate), '100'));

        $config = SwissVatConfig::query()->where('tenant_id', $tenantId)->first();
        $isEffective = ($config === null ? SwissVatMethod::Effective->value : $config->accounting_method) === SwissVatMethod::Effective->value;

        return SwissVatBezugsteuerEntry::create([
            'tenant_id' => $tenantId,
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_name' => (string) $data['supplier_name'],
            'supplier_country' => $country,
            'supplier_vat_number' => $data['supplier_vat_number'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'invoice_date' => $invoiceDate->toDateString(),
            'service_category' => (string) ($data['service_category'] ?? BezugsteuerCategory::OtherCrossBorderService->value),
            'description' => (string) $data['description'],
            'currency' => strtoupper((string) ($data['currency'] ?? 'CHF')),
            'original_net_amount' => SwissVatDecimal::round2($origNet),
            'exchange_rate' => $fx,
            'net_amount_chf' => $netChf,
            'vat_rate_percent' => $rate,
            'tax_amount_chf' => $taxChf,
            'deductible_input_tax_chf' => $isEffective ? $taxChf : '0.00',
            'afc_box' => $epoch['boxes']['bezugsteuer'],
            'detection_source' => 'manual',
            'status' => BezugsteuerStatus::Confirmed->value,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
            'created_by' => $userId,
        ]);
    }

    public function confirm(string $tenantId, string $id, ?string $userId): SwissVatBezugsteuerEntry
    {
        $entry = $this->findForTenant($tenantId, $id);

        if ($entry->status === BezugsteuerStatus::Declared->value) {
            throw SwissVatException::locked('bezugsteuer_already_declared');
        }

        $entry->update([
            'status' => BezugsteuerStatus::Confirmed->value,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ]);

        return $entry;
    }

    public function exclude(string $tenantId, string $id): SwissVatBezugsteuerEntry
    {
        $entry = $this->findForTenant($tenantId, $id);

        if ($entry->status === BezugsteuerStatus::Declared->value) {
            throw SwissVatException::locked('bezugsteuer_already_declared');
        }

        $entry->update([
            'status' => BezugsteuerStatus::Excluded->value,
        ]);

        return $entry;
    }

    /**
     * @return Collection<int, SwissVatBezugsteuerEntry>
     */
    public function confirmedForPeriod(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return SwissVatBezugsteuerEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [BezugsteuerStatus::Confirmed->value, BezugsteuerStatus::Declared->value])
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->orderBy('invoice_date')
            ->get();
    }

    private function findForTenant(string $tenantId, string $id): SwissVatBezugsteuerEntry
    {
        $entry = SwissVatBezugsteuerEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->first();

        if ($entry === null) {
            throw SwissVatException::notFound('bezugsteuer_not_found');
        }

        return $entry;
    }

    private function inferCategory(string $text): string
    {
        $lower = strtolower($text);

        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return $category;
                }
            }
        }

        return BezugsteuerCategory::OtherCrossBorderService->value;
    }
}

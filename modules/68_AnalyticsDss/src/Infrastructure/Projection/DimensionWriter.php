<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Projection;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AnalyticsDss\Domain\Services\HolidayCalendar;

/**
 * Keeps the conformed dimensions of the data mart in step with the operational master data. Every method is idempotent and
 * tenant scoped; a dimension row is written once and refreshed on demand.
 */
final class DimensionWriter
{
    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(private readonly HolidayCalendar $holidays) {}

    public function dateKey(string $tenantId, string $date): int
    {
        $day = new DateTimeImmutable($date);
        $key = (int) $day->format('Ymd');

        if (isset($this->seen["date|{$tenantId}|{$key}"])) {
            return $key;
        }

        $this->seen["date|{$tenantId}|{$key}"] = true;

        if (! DB::table('dim_date')->where('tenant_id', $tenantId)->where('date_key', $key)->exists()) {
            $now = now();
            DB::table('dim_date')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'date_key' => $key, 'date' => $day->format('Y-m-d'),
                'year' => (int) $day->format('Y'), 'quarter' => (int) ceil(((int) $day->format('n')) / 3), 'month' => (int) $day->format('n'),
                'week' => (int) $day->format('W'), 'day_of_week' => (int) $day->format('N'), 'is_weekend' => (int) $day->format('N') >= 6,
                'is_ch_holiday' => $this->holidays->isSwissHoliday($day), 'is_it_holiday' => $this->holidays->isItalianHoliday($day),
                'working_days' => $this->holidays->workingDaysInMonth($day), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $key;
    }

    /**
     * Legal entity of a ledger entry. A tenant without company rows is its own single legal entity (id = tenant id).
     */
    public function legalEntity(string $tenantId, ?string $companyId, string $fallbackCurrency): string
    {
        $entityId = $companyId !== null && $companyId !== '' ? $companyId : $tenantId;

        if (isset($this->seen["le|{$tenantId}|{$entityId}"])) {
            return $entityId;
        }

        $this->seen["le|{$tenantId}|{$entityId}"] = true;
        $company = $entityId !== $tenantId
            ? DB::table('companies')->where('tenant_id', $tenantId)->where('id', $entityId)->first()
            : null;
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        $values = [
            'name' => (string) ($company->legal_name ?? $tenant->name ?? $entityId),
            'base_currency' => strtoupper((string) ($company->currency ?? $tenant->default_currency ?? $fallbackCurrency)),
            'country' => $company->country ?? $tenant->country ?? null,
            'parent_legal_entity_id' => $company->parent_company_id ?? null,
            'updated_at' => now(),
        ];

        $this->upsert('dim_legal_entity', ['tenant_id' => $tenantId, 'legal_entity_id' => $entityId], $values);

        return $entityId;
    }

    /**
     * Returns the contact id when it is a known customer or supplier of the tenant, null otherwise.
     */
    public function counterparty(string $tenantId, ?string $contactId): ?string
    {
        if ($contactId === null || $contactId === '') {
            return null;
        }

        if (isset($this->seen["cp|{$tenantId}|{$contactId}"])) {
            return $contactId;
        }

        $contact = DB::table('contacts')->where('tenant_id', $tenantId)->where('id', $contactId)->first();

        if ($contact === null) {
            return null;
        }

        $this->seen["cp|{$tenantId}|{$contactId}"] = true;
        $name = (string) ($contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')));
        $uid = (string) ($contact->uid_number ?? '');
        $isSwiss = $uid !== '' || strtoupper((string) $contact->country) === 'CH';

        $this->upsert('dim_counterparty', ['tenant_id' => $tenantId, 'contact_id' => $contactId], [
            'name' => $name !== '' ? $name : $contactId,
            'kind' => in_array($contact->type, ['supplier', 'vendor'], true) ? 'supplier' : 'customer',
            'country' => strtoupper((string) $contact->country),
            'industry_code' => $uid !== '' ? $uid : ($contact->fiscal_code ?? null),
            'industry_code_system' => $isSwiss ? 'NOGA' : 'ATECO',
            'payment_terms_days' => (int) ($contact->payment_terms_days ?? 30),
            'cohort' => substr((string) $contact->created_at, 0, 7) ?: null,
            'updated_at' => now(),
        ]);

        return $contactId;
    }

    public function costCenter(string $tenantId, ?string $costCenterId): ?string
    {
        if ($costCenterId === null || $costCenterId === '') {
            return null;
        }

        if (isset($this->seen["cc|{$tenantId}|{$costCenterId}"])) {
            return $costCenterId;
        }

        $center = DB::table('cost_centers')->where('tenant_id', $tenantId)->where('id', $costCenterId)->first();

        if ($center === null) {
            return null;
        }

        $this->seen["cc|{$tenantId}|{$costCenterId}"] = true;
        $this->upsert('dim_cost_center', ['tenant_id' => $tenantId, 'cost_center_id' => $costCenterId], [
            'code' => $center->code ?? null, 'name' => (string) $center->name, 'updated_at' => now(),
        ]);

        return $costCenterId;
    }

    public function product(string $tenantId, ?string $productId): ?string
    {
        if ($productId === null || $productId === '') {
            return null;
        }

        if (isset($this->seen["ps|{$tenantId}|{$productId}"])) {
            return $productId;
        }

        $product = DB::table('products')->where('tenant_id', $tenantId)->where('id', $productId)->first();

        if ($product === null) {
            return null;
        }

        $this->seen["ps|{$tenantId}|{$productId}"] = true;
        $this->upsert('dim_product_service', ['tenant_id' => $tenantId, 'product_id' => $productId], [
            'sku' => $product->sku ?? null, 'name' => (string) $product->name, 'category' => $product->category_id ?? null, 'updated_at' => now(),
        ]);

        return $productId;
    }

    /**
     * ABC (cumulative share of the outbound value before the product: A below 80%, B below 95%) and XYZ (coefficient of variation of the monthly
     * outbound quantity: X up to 0.5, Y up to 1.0) classification of the products that moved.
     */
    public function classifyProducts(string $tenantId): void
    {
        $rows = DB::table('fact_operational_efficiency')
            ->where('tenant_id', $tenantId)->where('source_type', 'stock_movement')->whereNotNull('product_id')->whereNull('deleted_at')
            ->get(['product_id', 'work_date', 'value_out', 'quantity_out']);

        $value = [];
        $monthly = [];

        foreach ($rows as $row) {
            $product = (string) $row->product_id;
            $value[$product] = ($value[$product] ?? 0.0) + (float) $row->value_out;
            $month = substr((string) $row->work_date, 0, 7);
            $monthly[$product][$month] = ($monthly[$product][$month] ?? 0.0) + (float) $row->quantity_out;
        }

        arsort($value);
        $total = array_sum($value);
        $cumulative = 0.0;

        foreach ($value as $product => $amount) {
            // The class depends on the share already covered by the bigger products, so the biggest one is always A.
            $share = $total > 0.0 ? $cumulative / $total : 0.0;
            $cumulative += $amount;
            $series = array_values($monthly[$product] ?? []);
            $mean = $series !== [] ? array_sum($series) / count($series) : 0.0;
            $deviation = $series !== [] ? sqrt(array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $series)) / count($series)) : 0.0;
            $cv = $mean > 0.0 ? $deviation / $mean : 0.0;

            DB::table('dim_product_service')->where('tenant_id', $tenantId)->where('product_id', $product)->update([
                'abc_class' => $share < 0.8 ? 'A' : ($share < 0.95 ? 'B' : 'C'),
                'xyz_class' => $cv <= 0.5 ? 'X' : ($cv <= 1.0 ? 'Y' : 'Z'),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     */
    private function upsert(string $table, array $keys, array $values): void
    {
        $query = DB::table($table);

        foreach ($keys as $column => $value) {
            $query->where($column, $value);
        }

        if ($query->exists()) {
            $query->update($values);

            return;
        }

        DB::table($table)->insert([...$keys, ...$values, 'id' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now()]);
    }
}

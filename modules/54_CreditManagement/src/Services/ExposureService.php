<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use Illuminate\Support\Carbon;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\CreditManagement\Models\CreditScore;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Credit exposure and ageing of the receivables. The exposure of a customer is what it owes on open invoices plus the
 * unbilled value of its committed orders, set against its credit limit (0 means no limit) and the insured limit.
 * Amounts are decimal strings without currency conversion, like the sales credit check.
 */
final readonly class ExposureService
{
    /** @var list<string> */
    public const array BucketKeys = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function __construct(private ReceivablesLookupInterface $receivables, private InsuranceService $insurance) {}

    /**
     * @return array<string, mixed>
     */
    public function forCustomer(string $tenantId, string $customerId, Carbon $today): array
    {
        $invoices = $this->receivables->openInvoices($tenantId, $customerId);
        $name = $this->receivables->customerName($tenantId, $customerId) ?? '-';

        return $this->build($tenantId, $customerId, $name, $invoices, $today);
    }

    /**
     * The exposure of every customer with open invoices, highest exposure first.
     *
     * @return list<array<string, mixed>>
     */
    public function listing(string $tenantId, Carbon $today, bool $overLimitOnly = false): array
    {
        $byCustomer = [];

        foreach ($this->receivables->openInvoices($tenantId) as $invoice) {
            $byCustomer[$invoice['customer_id']][] = $invoice;
        }

        $names = $this->receivables->customerNames($tenantId, array_keys($byCustomer));
        $rows = [];

        foreach ($byCustomer as $customerId => $invoices) {
            $row = $this->build($tenantId, (string) $customerId, $names[$customerId] ?? '-', $invoices, $today);

            if (! $overLimitOnly || $row['is_over_limit']) {
                $rows[] = $row;
            }
        }

        usort($rows, static fn (array $left, array $right): int => Decimal::compare($right['exposure'], $left['exposure']));

        return $rows;
    }

    /**
     * Ageing of the open receivables, in total and per customer.
     *
     * @return array<string, mixed>
     */
    public function aging(string $tenantId, Carbon $today, ?string $customerId = null): array
    {
        $buckets = array_fill_keys(self::BucketKeys, ['amount' => '0.00', 'invoices_count' => 0]);
        $customers = [];
        $total = '0.00';

        foreach ($this->receivables->openInvoices($tenantId, $customerId) as $invoice) {
            $key = self::bucketFor($invoice['due_on'], $today);
            $buckets[$key] = ['amount' => Decimal::round2(Decimal::add($buckets[$key]['amount'], $invoice['outstanding'])), 'invoices_count' => $buckets[$key]['invoices_count'] + 1];
            $total = Decimal::round2(Decimal::add($total, $invoice['outstanding']));
            $row = $customers[$invoice['customer_id']] ?? ['customer_id' => $invoice['customer_id'], 'buckets' => array_fill_keys(self::BucketKeys, '0.00'), 'total' => '0.00'];
            $row['buckets'][$key] = Decimal::round2(Decimal::add($row['buckets'][$key], $invoice['outstanding']));
            $row['total'] = Decimal::round2(Decimal::add($row['total'], $invoice['outstanding']));
            $customers[$invoice['customer_id']] = $row;
        }

        $names = $this->receivables->customerNames($tenantId, array_keys($customers));
        $rows = array_map(static fn (array $row): array => ['customer_name' => $names[$row['customer_id']] ?? '-', ...$row], array_values($customers));
        usort($rows, static fn (array $left, array $right): int => Decimal::compare($right['total'], $left['total']));

        $list = [];

        foreach ($buckets as $key => $bucket) {
            $list[] = ['key' => $key, ...$bucket];
        }

        return ['as_of' => $today->toDateString(), 'buckets' => $list, 'total' => $total, 'customers' => $rows];
    }

    /**
     * Days a due date is past on the given day, never negative; an invoice without a due date is not overdue.
     */
    public static function daysOverdue(?string $dueOn, Carbon $today): int
    {
        if ($dueOn === null) {
            return 0;
        }

        $due = Carbon::parse($dueOn)->startOfDay();

        return $due->lt($today->copy()->startOfDay()) ? (int) $due->diffInDays($today->copy()->startOfDay(), true) : 0;
    }

    /**
     * The ageing bucket of an invoice: current (not yet due), 1-30, 31-60, 61-90 and over 90 days overdue.
     */
    public static function bucketFor(?string $dueOn, Carbon $today): string
    {
        return match (true) {
            ($days = self::daysOverdue($dueOn, $today)) <= 0 => 'current',
            $days <= 30 => 'd1_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /**
     * @param  list<array{invoice_id: string, customer_id: string, invoice_number: string, issued_on: string, due_on: string|null, currency: string, gross: string, outstanding: string}>  $invoices
     * @return array<string, mixed>
     */
    private function build(string $tenantId, string $customerId, string $name, array $invoices, Carbon $today): array
    {
        $open = '0.00';
        $overdue = '0.00';
        $overdueCount = 0;
        $maxDays = 0;

        foreach ($invoices as $invoice) {
            $open = Decimal::add($open, $invoice['outstanding']);
            $days = self::daysOverdue($invoice['due_on'], $today);

            if ($days > 0) {
                $overdue = Decimal::add($overdue, $invoice['outstanding']);
                $overdueCount++;
                $maxDays = max($maxDays, $days);
            }
        }

        $orders = $this->receivables->committedOrders($tenantId, $customerId);
        $limit = $this->receivables->creditLimit($tenantId, $customerId);
        $exposure = Decimal::round2(Decimal::add($open, $orders));
        $hasLimit = Decimal::isPositive($limit);
        $insured = $this->insurance->insuredLimit($tenantId, $customerId, $today);
        $score = CreditScore::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->orderByDesc('scored_on')->orderByDesc('created_at')->first();
        $uninsured = Decimal::sub($exposure, $insured);

        return [
            'customer_id' => $customerId,
            'customer_name' => $name,
            'credit_limit' => $limit,
            'open_invoices' => Decimal::round2($open),
            'overdue_amount' => Decimal::round2($overdue),
            'overdue_invoices_count' => $overdueCount,
            'max_days_overdue' => $maxDays,
            'open_orders' => $orders,
            'exposure' => $exposure,
            'available_credit' => $hasLimit ? Decimal::round2(Decimal::sub($limit, $exposure)) : null,
            'utilisation_percent' => $hasLimit ? Decimal::round2(Decimal::mul(Decimal::div($exposure, $limit), '100')) : null,
            'is_over_limit' => $hasLimit && Decimal::compare($exposure, $limit) > 0,
            'insured_limit' => $insured,
            'uninsured_exposure' => Decimal::isNegative($uninsured) ? '0.00' : Decimal::round2($uninsured),
            'rating' => $score?->rating,
            'score' => $score?->score,
        ];
    }
}

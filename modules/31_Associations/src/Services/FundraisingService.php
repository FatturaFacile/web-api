<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Contracts\PdfGenerator;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Campaign;
use Modules\Associations\Models\CampaignExpense;
use Modules\Associations\Models\Donation;
use Modules\Associations\Models\DonationReceipt;
use Modules\Associations\Models\FivePerMilleAllocation;
use Modules\Associations\Models\FivePerMilleExpense;
use Modules\Associations\Models\Reimbursement;
use Modules\Associations\Support\CsvFile;
use Modules\Associations\Support\Money;

/**
 * Donations, campaigns and the 5x1000. Money is posted to the ledger on the account of the campaign or of the settings
 * and on the cost centre of the campaign, so every campaign can be reported on its own. Receipts are numbered per year;
 * their text says what was received and does not claim any tax benefit, which depends on the donor and on the status of
 * the association and has to be stated by a professional.
 */
final class FundraisingService
{
    private const string ReceiptSequence = 'association_donation_receipt';

    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly TenantContext $tenantContext,
        private readonly AssociationLedgerPoster $ledger,
        private readonly PdfGenerator $pdf,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordDonation(array $data, ?string $userId): Donation
    {
        return DB::transaction(function () use ($data, $userId): Donation {
            $campaign = isset($data['campaign_id']) ? Campaign::query()->findOrFail($data['campaign_id']) : null;
            $donation = Donation::create([
                ...$data,
                'amount' => Money::normalize((string) $data['amount']),
                'cost_center_id' => $data['cost_center_id'] ?? $campaign?->cost_center_id,
                'status' => 'recorded',
            ]);

            if ($donation->kind === Donation::Money) {
                $account = $data['revenue_account_id'] ?? $campaign?->revenue_account_id ?? AssociationSetting::current()->donation_revenue_account_id;

                if ($account === null) {
                    throw new AssociationException(__('association_finance.donation_account_missing'));
                }

                $entry = $this->ledger->postInflow((string) $account, Money::normalize((string) $donation->amount), CarbonImmutable::parse($donation->received_on), (string) ($donation->payment_method ?? 'bank'), (string) __('association_finance.donation_posting'), 'association_donation', $donation->id, ['cost_center_id' => $donation->cost_center_id], $userId);
                $donation->update(['journal_entry_id' => $entry->id, 'revenue_account_id' => $account]);
            }

            return $donation;
        });
    }

    public function issueReceipt(Donation $donation, ?CarbonImmutable $issuedOn = null): DonationReceipt
    {
        $issuedOn ??= CarbonImmutable::today();

        if ($donation->donor === null) {
            throw new AssociationException(__('association_finance.receipt_needs_donor'));
        }

        return DB::transaction(function () use ($donation, $issuedOn): DonationReceipt {
            Donation::query()->whereKey($donation->id)->lockForUpdate()->first();

            if (DonationReceipt::query()->where('donation_id', $donation->id)->whereNull('cancelled_at')->exists()) {
                throw AssociationException::conflict(__('association_finance.receipt_exists'));
            }

            $sequence = $this->numbering->next(self::ReceiptSequence, $issuedOn->year, (string) $this->tenantContext->getTenantId());
            $donor = $donation->donor;

            return DonationReceipt::create([
                'donation_id' => $donation->id,
                'number' => sprintf('%s%d-%04d', AssociationSetting::current()->donation_receipt_prefix ?? 'DON-', $sequence['fiscal_year'], $sequence['sequential_number']),
                'fiscal_year' => $sequence['fiscal_year'],
                'sequential_number' => $sequence['sequential_number'],
                'issued_on' => $issuedOn->toDateString(),
                'amount' => Money::normalize((string) $donation->amount),
                'holder' => [
                    'name' => $donor->name,
                    'tax_code' => $donor->tax_code,
                    'address' => $donor->address,
                    'kind' => $donation->kind,
                    'in_kind_description' => $donation->in_kind_description,
                    'received_on' => CarbonImmutable::parse($donation->received_on)->toDateString(),
                    'payment_method' => $donation->payment_method,
                    'campaign' => $donation->campaign?->name,
                ],
            ]);
        });
    }

    public function cancelReceipt(DonationReceipt $receipt, string $reason): DonationReceipt
    {
        if ($receipt->cancelled_at !== null) {
            throw AssociationException::conflict(__('association_finance.receipt_cancelled'));
        }

        $receipt->update(['cancelled_at' => now(), 'cancellation_reason' => $reason]);

        return $receipt;
    }

    public function receiptPdf(DonationReceipt $receipt): string
    {
        return $this->pdf->render('associations::pdf.donation-receipt', ['tenant' => $this->tenantContext->getTenant(), 'receipt' => $receipt]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordCampaignExpense(Campaign $campaign, array $data, ?string $userId): CampaignExpense
    {
        return DB::transaction(function () use ($campaign, $data, $userId): CampaignExpense {
            $expense = CampaignExpense::create([...$data, 'campaign_id' => $campaign->id, 'amount' => Money::normalize((string) $data['amount'])]);
            $account = $data['expense_account_id'] ?? $campaign->expense_account_id;

            if ($account === null) {
                throw new AssociationException(__('association_finance.expense_account_missing'));
            }

            $entry = $this->ledger->postOutflow((string) $account, Money::normalize((string) $expense->amount), CarbonImmutable::parse($expense->incurred_on), (string) ($data['payment_method'] ?? 'bank'), (string) $expense->description, 'association_campaign_expense', $expense->id, ['cost_center_id' => $campaign->cost_center_id], $userId);
            $expense->update(['journal_entry_id' => $entry->id, 'expense_account_id' => $account]);

            return $expense;
        });
    }

    /**
     * The separate account of a campaign: income, costs and reimbursements charged to it, and the result.
     *
     * @return array{campaign: array<string, mixed>, donations: array{count: int, money: string, in_kind: string}, expenses: string, reimbursements: string, result: string, lines: list<array{date: string, kind: string, description: string, amount: string}>}
     */
    public function campaignReport(Campaign $campaign): array
    {
        $donations = Donation::query()->where('campaign_id', $campaign->id)->where('status', 'recorded')->orderBy('received_on')->get();
        $expenses = CampaignExpense::query()->where('campaign_id', $campaign->id)->orderBy('incurred_on')->get();
        $reimbursements = Reimbursement::query()->where('campaign_id', $campaign->id)->where('status', Reimbursement::Paid)->orderBy('paid_on')->get();

        $sum = static fn (iterable $rows): string => collect($rows)->reduce(static fn (string $total, $row): string => Money::add($total, Money::normalize((string) $row->amount)), '0.00');
        $money = $sum($donations->where('kind', Donation::Money));
        $totalExpenses = $sum($expenses);
        $totalReimbursements = $sum($reimbursements);
        $lines = [];

        foreach ($donations as $donation) {
            $lines[] = ['date' => $donation->received_on->toDateString(), 'kind' => 'donation_'.$donation->kind, 'description' => (string) ($donation->purpose ?? ''), 'amount' => Money::normalize((string) $donation->amount)];
        }

        foreach ($expenses as $expense) {
            $lines[] = ['date' => $expense->incurred_on->toDateString(), 'kind' => 'expense', 'description' => (string) $expense->description, 'amount' => Money::sub('0', Money::normalize((string) $expense->amount))];
        }

        foreach ($reimbursements as $reimbursement) {
            $lines[] = ['date' => $reimbursement->paid_on?->toDateString() ?? '', 'kind' => 'reimbursement', 'description' => (string) $reimbursement->description, 'amount' => Money::sub('0', Money::normalize((string) $reimbursement->amount))];
        }

        usort($lines, static fn (array $left, array $right): int => strcmp($left['date'], $right['date']));

        return [
            'campaign' => ['id' => $campaign->id, 'code' => $campaign->code, 'name' => $campaign->name, 'kind' => $campaign->kind, 'starts_on' => $campaign->starts_on?->toDateString(), 'ends_on' => $campaign->ends_on?->toDateString()],
            'donations' => ['count' => $donations->count(), 'money' => $money, 'in_kind' => $sum($donations->where('kind', Donation::InKind))],
            'expenses' => $totalExpenses,
            'reimbursements' => $totalReimbursements,
            'result' => Money::sub(Money::sub($money, $totalExpenses), $totalReimbursements),
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordFivePerMille(array $data, ?string $userId): FivePerMilleAllocation
    {
        return DB::transaction(function () use ($data, $userId): FivePerMilleAllocation {
            $allocation = FivePerMilleAllocation::create([...$data, 'amount' => Money::normalize((string) $data['amount'])]);
            $account = AssociationSetting::current()->five_per_mille_revenue_account_id;

            if ($account !== null) {
                $entry = $this->ledger->postInflow((string) $account, Money::normalize((string) $allocation->amount), CarbonImmutable::parse($allocation->received_on), (string) ($allocation->payment_method ?? 'bank'), (string) __('association_finance.five_per_mille_posting', ['year' => $allocation->reference_year]), 'association_five_per_mille', $allocation->id, [], $userId);
                $allocation->update(['journal_entry_id' => $entry->id]);
            }

            return $allocation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordFivePerMilleExpense(FivePerMilleAllocation $allocation, array $data): FivePerMilleExpense
    {
        return DB::transaction(static function () use ($allocation, $data): FivePerMilleExpense {
            FivePerMilleAllocation::query()->whereKey($allocation->id)->lockForUpdate()->first();
            $spent = FivePerMilleExpense::query()->where('allocation_id', $allocation->id)->pluck('amount')
                ->reduce(static fn (string $total, mixed $amount): string => Money::add($total, Money::normalize((string) $amount)), '0.00');
            $amount = Money::normalize((string) $data['amount']);

            if (Money::compare(Money::add($spent, $amount), Money::normalize((string) $allocation->amount)) > 0) {
                throw new AssociationException(__('association_finance.five_per_mille_exceeded'));
            }

            return FivePerMilleExpense::create([...$data, 'allocation_id' => $allocation->id, 'amount' => $amount]);
        });
    }

    /**
     * How an allocation of the 5x1000 was used, by category.
     *
     * @return array{allocation: array<string, mixed>, categories: list<array{code: string, name: string, amount: string}>, spent: string, remaining: string}
     */
    public function fivePerMilleReport(FivePerMilleAllocation $allocation): array
    {
        $expenses = FivePerMilleExpense::query()->with('category')->where('allocation_id', $allocation->id)->get();
        $categories = [];

        foreach ($expenses->groupBy('category_id') as $rows) {
            $category = $rows->first()->category;
            $categories[] = [
                'code' => (string) $category->code,
                'name' => (string) $category->name,
                'amount' => $rows->reduce(static fn (string $total, FivePerMilleExpense $expense): string => Money::add($total, Money::normalize((string) $expense->amount)), '0.00'),
            ];
        }

        usort($categories, static fn (array $left, array $right): int => strcmp($left['code'], $right['code']));
        $spent = collect($categories)->reduce(static fn (string $total, array $row): string => Money::add($total, $row['amount']), '0.00');

        return [
            'allocation' => ['id' => $allocation->id, 'reference_year' => $allocation->reference_year, 'received_on' => $allocation->received_on->toDateString(), 'amount' => Money::normalize((string) $allocation->amount)],
            'categories' => $categories,
            'spent' => $spent,
            'remaining' => Money::sub(Money::normalize((string) $allocation->amount), $spent),
        ];
    }

    public function fivePerMilleCsv(FivePerMilleAllocation $allocation): string
    {
        $rows = FivePerMilleExpense::query()->with('category')->where('allocation_id', $allocation->id)->orderBy('incurred_on')->get()
            ->map(static fn (FivePerMilleExpense $expense): array => [$expense->incurred_on->toDateString(), $expense->category->code, $expense->category->name, $expense->description, Money::normalize((string) $expense->amount)]);

        return CsvFile::write(['date', 'category_code', 'category', 'description', 'amount'], $rows);
    }
}

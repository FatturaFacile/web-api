<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Modules\DebtCollection\Models\Batch;
use Modules\DebtCollection\Models\Dossier;
use Modules\DebtCollection\Models\Notice;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * Figures of the dunning dashboard: overdue positions per currency and jurisdiction, what the proposal would send now,
 * notices of the last 30 days, open enforcement dossiers and PEC notices still waiting for their delivery receipt.
 */
final class DashboardService
{
    public function __construct(private readonly ProposalService $proposals) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, Carbon $asOf): array
    {
        $tenantCountry = (string) (Tenant::query()->whereKey($tenantId)->value('country') ?? 'IT');
        $items = ReceivableItem::query()->whereIn('status', [ReceivableItem::StatusOpen, ReceivableItem::StatusPartiallyPaid, ReceivableItem::StatusDisputed])
            ->where('open_amount', '>', 0)->where('document_type', '!=', ProposalService::ChargeDocumentType)->whereDate('due_date', '<', $asOf->toDateString())->get();
        $contacts = Contact::query()->whereIn('id', $items->pluck('customer_id')->unique()->all())->get()->keyBy('id');

        $overdue = [];

        foreach ($items as $item) {
            $contact = $contacts->get($item->customer_id);
            $jurisdiction = $contact === null ? 'IT' : $this->proposals->jurisdictionOf($contact, $tenantCountry);
            $key = $item->currency.'|'.$jurisdiction;
            $overdue[$key] ??= ['currency' => (string) $item->currency, 'jurisdiction' => $jurisdiction, 'amount' => '0', 'items' => 0];
            $overdue[$key]['amount'] = Decimal::add($overdue[$key]['amount'], (string) $item->open_amount);
            $overdue[$key]['items']++;
        }

        $proposal = $this->proposals->build($tenantId, ['include_excluded' => true], $asOf);
        $byLevel = ['0' => 0, '1' => 0, '2' => 0, '3' => 0];
        $excluded = 0;
        $interest = [];

        foreach ($proposal['items'] as $row) {
            if ($row['status'] === ProposalService::Proposed) {
                $byLevel[(string) $row['proposed_level']]++;
                $interest[$row['currency']] = Decimal::add($interest[$row['currency']] ?? '0', (string) $row['interest_amount']);
            } elseif ($row['status'] === ProposalService::Excluded) {
                $excluded++;
            }
        }

        $recent = Notice::query()->where('created_at', '>=', $asOf->copy()->subDays(30))->where('status', '!=', 'cancelled')->get();

        return [
            'overdue' => array_values(array_map(static fn (array $row): array => [...$row, 'amount' => Decimal::round2($row['amount'])], $overdue)),
            'proposals' => ['total' => array_sum($byLevel), 'by_level' => $byLevel],
            'excluded' => $excluded,
            'notices_30d' => [
                'sent' => $recent->whereIn('status', ['sent', 'delivered'])->count(),
                'failed' => $recent->where('status', 'failed')->count(),
                'delivered' => $recent->where('status', 'delivered')->count(),
            ],
            'enforcement_open' => Dossier::query()->whereIn('status', ['generated', 'submitted'])->count(),
            'pec_awaiting_receipts' => Notice::query()->where('channel', 'pec')->where('status', 'sent')->count(),
            'interest_accrued' => array_map(static fn (string $currency, string $amount): array => ['currency' => $currency, 'amount' => Decimal::round2($amount)], array_keys($interest), array_values($interest)),
            'batches_running' => Batch::query()->whereIn('status', ['pending', 'processing'])->count(),
        ];
    }
}

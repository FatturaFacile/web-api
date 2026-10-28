<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Treasury\Contracts\ReceivableCollectionServiceInterface;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Treasury\Models\ReceivableCollection;

final readonly class ReceivableCollectionService implements ReceivableCollectionServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher
    ) {}

    public function scheduleCollection(array $data): ReceivableCollection
    {
        $dueDate = $data['due_date'] ?? now()->addDays(30)->toDateString();
        $agingBucket = $this->calculateAgingBucket($dueDate);

        /** @var ReceivableCollection $collection */
        $collection = ReceivableCollection::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $data['tenant_id'],
            'company_id' => $data['company_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? (string) Str::uuid7(),
            'invoice_id' => $data['invoice_id'] ?? null,
            'expected_amount' => (float) $data['expected_amount'],
            'collected_amount' => 0.00,
            'due_date' => $dueDate,
            'payment_method' => $data['payment_method'] ?? 'bank_transfer',
            'status' => 'pending',
            'aging_bucket' => $agingBucket,
            'dunning_level' => 0,
        ]);

        $this->auditLedger->record(
            action: 'collection_scheduled',
            model: $collection,
            payloadBefore: null,
            payloadAfter: $collection->toArray(),
            moduleCode: 'MODULE_24_TREASURY'
        );

        return $collection;
    }

    public function getCustomerAging(string $tenantId, ?string $companyId = null): array
    {
        $query = ReceivableCollection::where('tenant_id', $tenantId)
            ->whereIn(DB::raw('LOWER(status)'), ['pending', 'partial', 'overdue']);

        if (! empty($companyId)) {
            $query->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            });
        }

        $items = $query->get();

        $buckets = [
            'current' => 0.0,
            '1_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            'over_90' => 0.0,
        ];

        $totalOpen = 0.0;
        foreach ($items as $item) {
            $open = (float) $item->expected_amount - (float) $item->collected_amount;
            if ($open <= 0) {
                continue;
            }

            $currentBucket = $this->calculateAgingBucket((string) $item->due_date);
            if (isset($buckets[$currentBucket])) {
                $buckets[$currentBucket] += $open;
            } else {
                $buckets['over_90'] += $open;
            }
            $totalOpen += $open;
        }

        return [
            'as_of_date' => now()->toDateString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'currency' => 'EUR',
            'total_open_receivables' => round($totalOpen, 2),
            'buckets' => array_map(fn ($val) => round($val, 2), $buckets),
        ];
    }

    public function recordCollectionReceived(string $collectionId, float $amount, string $collectionDate): ReceivableCollection
    {
        return DB::transaction(function () use ($collectionId, $amount, $collectionDate) {
            /** @var ReceivableCollection $collection */
            $collection = ReceivableCollection::where('id', $collectionId)->lockForUpdate()->firstOrFail();

            if ($amount <= 0) {
                throw new InvalidArgumentException('Collected amount must be positive.');
            }

            $before = $collection->toArray();
            $newCollected = (float) $collection->collected_amount + $amount;
            $status = $newCollected >= (float) $collection->expected_amount ? 'collected' : 'partial';

            $collection->update([
                'collected_amount' => $newCollected,
                'collection_date' => $collectionDate,
                'status' => $status,
            ]);

            $event = new CollectionReceivedEvent(
                collectionId: (string) $collection->id,
                customerId: (string) $collection->customer_id,
                invoiceId: $collection->invoice_id ? (string) $collection->invoice_id : null,
                collectedAmount: $amount,
                collectionDate: $collectionDate,
                tenantId: (string) $collection->tenant_id,
                companyId: (string) ($collection->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            $this->auditLedger->record(
                action: 'collection_received',
                model: $collection,
                payloadBefore: $before,
                payloadAfter: $collection->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $collection;
        });
    }

    public function evaluateDunningReadiness(string $tenantId): array
    {
        $today = now()->toDateString();
        $overdue = ReceivableCollection::where('tenant_id', $tenantId)
            ->whereIn(DB::raw('LOWER(status)'), ['pending', 'partial', 'overdue'])
            ->where('due_date', '<', $today)
            ->get();

        $dunningCandidates = [];
        foreach ($overdue as $item) {
            $daysOverdue = abs((int) now()->diffInDays($item->due_date));
            $newDunning = $item->dunning_level;

            if ($daysOverdue >= 60) {
                $newDunning = 3;
            } elseif ($daysOverdue >= 30) {
                $newDunning = 2;
            } elseif ($daysOverdue >= 10) {
                $newDunning = 1;
            }

            if ($newDunning > $item->dunning_level) {
                $item->update([
                    'dunning_level' => $newDunning,
                    'status' => 'overdue',
                    'aging_bucket' => $this->calculateAgingBucket((string) $item->due_date),
                ]);
                $dunningCandidates[] = $item;
            }
        }

        return $dunningCandidates;
    }

    private function calculateAgingBucket(string $dueDate): string
    {
        $due = strtotime($dueDate);
        $today = strtotime(date('Y-m-d'));

        if ($due >= $today) {
            return 'current';
        }

        $daysPast = (int) floor(($today - $due) / 86400);

        if ($daysPast <= 30) {
            return '1_30';
        }
        if ($daysPast <= 60) {
            return '31_60';
        }
        if ($daysPast <= 90) {
            return '61_90';
        }

        return 'over_90';
    }
}

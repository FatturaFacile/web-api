<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services\Projections;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Support\Amount;

/**
 * Open items (receivables and payables): documents issued or received, credit notes, payments reconciled and credit limit changes.
 * Events: InvoiceIssued / InvoiceReceived {document_id, document_number, partner_id, partner_type, issue_date, due_date, currency, gross},
 * CreditNoteIssued {credit_note_id, credit_note_number, original_invoice_id, partner_id, credited_amount}, PaymentReconciled
 * {document_id, partner_type, paid_amount}, CreditLimitAdjusted {customer_id, old_limit, new_limit}.
 */
final class OpenItemsProjector implements StateProjector
{
    public const array BUCKETS = ['current', '1_30', '31_60', '61_90', '90_plus'];

    public function aggregateType(): AggregateType
    {
        return AggregateType::OpenItems;
    }

    public function checkpointDomain(): QueryDomain
    {
        return QueryDomain::Aging;
    }

    public function usesCheckpoint(QueryDomain $domain): bool
    {
        return true;
    }

    public function initialState(QueryDomain $domain, array $filters): array
    {
        return ['documents' => [], 'credit_limits' => []];
    }

    public function apply(array $state, BiTemporalEvent $event): array
    {
        $payload = $event->payload;

        switch ($event->event_type) {
            case 'InvoiceIssued':
            case 'InvoiceReceived':
                $id = (string) $payload['document_id'];
                $document = $state['documents'][$id] ?? $this->blank();
                $state['documents'][$id] = [
                    ...$document,
                    'document_number' => (string) ($payload['document_number'] ?? ''),
                    'partner_id' => (string) ($payload['partner_id'] ?? ''),
                    'partner_type' => (string) ($payload['partner_type'] ?? ($event->event_type === 'InvoiceReceived' ? 'supplier' : 'customer')),
                    'issue_date' => (string) ($payload['issue_date'] ?? substr((string) $event->valid_at, 0, 10)),
                    'due_date' => isset($payload['due_date']) ? (string) $payload['due_date'] : null,
                    'currency' => (string) ($payload['currency'] ?? 'EUR'),
                    'amount' => Amount::of($payload['gross'] ?? 0),
                ];
                break;

            case 'CreditNoteIssued':
                $original = (string) ($payload['original_invoice_id'] ?? '');

                if ($original !== '') {
                    $document = $state['documents'][$original] ?? $this->blank();
                    $state['documents'][$original] = [...$document, 'credited' => Amount::add($document['credited'], $payload['credited_amount'] ?? 0)];
                } else {
                    $id = (string) $payload['credit_note_id'];
                    $document = $state['documents'][$id] ?? $this->blank();
                    $state['documents'][$id] = [
                        ...$document,
                        'document_number' => (string) ($payload['credit_note_number'] ?? ''),
                        'partner_id' => (string) ($payload['partner_id'] ?? ''),
                        'partner_type' => 'customer',
                        'issue_date' => substr((string) $event->valid_at, 0, 10),
                        'due_date' => null,
                        'amount' => Amount::neg($payload['credited_amount'] ?? 0),
                    ];
                }
                break;

            case 'PaymentReconciled':
                $id = (string) $payload['document_id'];
                $document = $state['documents'][$id] ?? $this->blank();
                $state['documents'][$id] = [
                    ...$document,
                    'paid' => Amount::add($document['paid'], $payload['paid_amount'] ?? 0),
                    'partner_type' => $document['partner_type'] !== '' ? $document['partner_type'] : (string) ($payload['partner_type'] ?? ''),
                ];
                break;

            case 'CreditLimitAdjusted':
                $customer = (string) $payload['customer_id'];
                $current = $state['credit_limits'][$customer] ?? null;

                // The limit in force is the one with the latest valid time (the sequence breaks a tie).
                $key = (string) $event->valid_at.'#'.str_pad((string) $event->sequence_number, 12, '0', STR_PAD_LEFT);

                if ($current === null || strcmp($key, (string) $current['key']) > 0) {
                    $state['credit_limits'][$customer] = ['limit' => Amount::money($payload['new_limit'] ?? 0), 'key' => $key];
                }
                break;
        }

        return $state;
    }

    public function render(QueryDomain $domain, array $state, array $filters, CarbonImmutable $validAt, string $tenantId): array
    {
        $partnerType = (string) ($filters['partner_type'] ?? '');
        $partnerId = (string) ($filters['partner_id'] ?? '');
        $today = $validAt->startOfDay();
        $buckets = array_fill_keys(self::BUCKETS, '0');
        $rows = [];
        $open = '0';

        /** @var array<string, array<string, mixed>> $documents */
        $documents = $state['documents'];

        foreach ($documents as $id => $document) {
            if ($partnerType !== '' && $document['partner_type'] !== $partnerType) {
                continue;
            }

            if ($partnerId !== '' && $document['partner_id'] !== $partnerId) {
                continue;
            }

            $openAmount = Amount::sub(Amount::sub($document['amount'], $document['paid']), $document['credited']);

            if (Amount::isZero($openAmount)) {
                continue;
            }

            $reference = (string) ($document['due_date'] ?? $document['issue_date'] ?? $today->toDateString());
            $days = max(0, (int) CarbonImmutable::parse($reference, 'UTC')->startOfDay()->diffInDays($today, false));
            $bucket = match (true) {
                $days === 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };

            $rows[] = [
                'document_id' => (string) $id,
                'document_number' => $document['document_number'],
                'partner_id' => $document['partner_id'],
                'partner_type' => $document['partner_type'],
                'due_date' => $document['due_date'],
                'amount' => Amount::money($document['amount']),
                'open_amount' => Amount::money($openAmount),
                'days_overdue' => $days,
                'bucket' => $bucket,
            ];
            $buckets[$bucket] = Amount::add($buckets[$bucket], $openAmount);
            $open = Amount::add($open, $openAmount);
        }

        usort($rows, static fn (array $a, array $b): int => [(string) $a['due_date'], $a['document_id']] <=> [(string) $b['due_date'], $b['document_id']]);

        /** @var array<string, array{limit: string}> $limits */
        $limits = $state['credit_limits'];

        return [
            'rows' => $rows,
            'totals' => [
                'open_amount' => Amount::money($open),
                'buckets' => array_map(Amount::money(...), $buckets),
                'credit_limits' => array_map(static fn (array $limit): string => $limit['limit'], $limits),
            ],
        ];
    }

    public function comparable(QueryDomain $domain, array $rows): array
    {
        $comparable = [];

        foreach ($rows as $row) {
            $comparable[(string) $row['document_id']] = [
                'label' => (string) ($row['document_number'] !== '' ? $row['document_number'] : $row['document_id']),
                'value' => (string) $row['open_amount'],
                'weight' => (string) $row['open_amount'],
            ];
        }

        return $comparable;
    }

    public function matches(QueryDomain $domain, BiTemporalEvent $event, array $filters): bool
    {
        $partnerType = (string) ($filters['partner_type'] ?? '');

        if ($partnerType === '') {
            return true;
        }

        $type = (string) ($event->payload['partner_type'] ?? match ($event->event_type) {
            'InvoiceReceived' => 'supplier',
            default => 'customer',
        });

        return $type === $partnerType;
    }

    public function affectedKeys(QueryDomain $domain, BiTemporalEvent $event, array $filters): array
    {
        $payload = $event->payload;

        return match ($event->event_type) {
            'CreditNoteIssued' => [(string) (($payload['original_invoice_id'] ?? '') !== '' ? $payload['original_invoice_id'] : $payload['credit_note_id'])],
            'CreditLimitAdjusted' => [],
            default => [(string) ($payload['document_id'] ?? '')],
        };
    }

    public function amountDelta(QueryDomain $domain, BiTemporalEvent $event, array $filters): string
    {
        $payload = $event->payload;

        return Amount::money(match ($event->event_type) {
            'InvoiceIssued', 'InvoiceReceived' => $payload['gross'] ?? 0,
            'CreditNoteIssued' => Amount::neg($payload['credited_amount'] ?? 0),
            'PaymentReconciled' => Amount::neg($payload['paid_amount'] ?? 0),
            default => 0,
        });
    }

    public function sourceDocument(BiTemporalEvent $event): ?string
    {
        $payload = $event->payload;
        $number = (string) ($payload['document_number'] ?? $payload['credit_note_number'] ?? '');

        return $number !== '' ? $number : (isset($payload['document_id']) ? (string) $payload['document_id'] : null);
    }

    /**
     * @return array{document_number: string, partner_id: string, partner_type: string, issue_date: string|null, due_date: string|null, currency: string, amount: string, paid: string, credited: string}
     */
    private function blank(): array
    {
        return [
            'document_number' => '',
            'partner_id' => '',
            'partner_type' => '',
            'issue_date' => null,
            'due_date' => null,
            'currency' => 'EUR',
            'amount' => Amount::of(0),
            'paid' => Amount::of(0),
            'credited' => Amount::of(0),
        ];
    }
}

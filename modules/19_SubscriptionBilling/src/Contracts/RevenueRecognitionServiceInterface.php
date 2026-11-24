<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Carbon\CarbonInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\RevenueSchedule;
use Modules\SubscriptionBilling\Models\Subscription;

/**
 * IFRS 15 revenue recognition of the subscriptions: obligations from issued documents, monthly slices, postings.
 */
interface RevenueRecognitionServiceInterface
{
    /**
     * The revenue contract of a subscription, created on first use.
     */
    public function createRevenueContract(Subscription $subscription): RevenueContract;

    /**
     * Allocates the contract price to its obligations by relative standalone selling price.
     */
    public function allocateTransactionPrice(RevenueContract $contract): void;

    /**
     * Slices the obligations of the contract that have none yet.
     *
     * @return list<RevenueSchedule>
     */
    public function generateRevenueSchedules(RevenueContract $contract): array;

    /**
     * Obligations and slices of the lines of an issued document of the subscription.
     *
     * @param  list<array{description: string, amount: string, method: string, start: CarbonInterface, end: CarbonInterface}>  $obligations
     */
    public function scheduleDocument(Subscription $subscription, string $documentType, string $documentId, ?InvoiceSchedule $schedule, array $obligations): void;

    /**
     * Releases the deferred revenue a refund credit note gave back.
     */
    public function releaseCredit(Subscription $subscription, SalesInvoice $creditNote, string $netAmount, CarbonInterface $date, ?string $actorUserId): ?JournalEntry;

    /**
     * Posts the revenue due up to the period date.
     *
     * @return array{entries_count: int, total_amount: string, journal_entry_ids: list<string>, months: list<string>, errors: list<array{month: string, error: string}>}
     */
    public function recognizeRevenue(RecognizeRevenueDTO $dto): array;
}

<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Carbon\CarbonImmutable;
use Modules\FranchiseNetwork\Domain\Enums\CollectionScheme;
use Modules\FranchiseNetwork\Domain\Enums\PaymentMethod;
use Modules\FranchiseNetwork\Domain\Enums\StatementStatus;
use Modules\FranchiseNetwork\Domain\Support\Dec;
use Modules\FranchiseNetwork\Models\CollectionBatch;
use Modules\FranchiseNetwork\Models\FranchiseAgreement;
use Modules\FranchiseNetwork\Models\RoyaltyRun;
use Modules\FranchiseNetwork\Models\RoyaltyStatement;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Direct debit readiness of an invoiced run: the invoices of the stores whose agreement names SEPA SDD or LSV+ are grouped into one
 * batch per scheme. A store without a signed mandate is not collected: it appears in the batch as excluded, with the reason, and
 * is paid by transfer as usual. The batch holds the data of the bank file (mandate, amount, invoice); the bank export is a
 * separate step of the treasury module.
 */
final class CollectionBatchService
{
    /**
     * @return list<CollectionBatch>
     */
    public function build(RoyaltyRun $run): array
    {
        $statements = RoyaltyStatement::query()->with('store')->where('run_id', $run->id)->where('status', StatementStatus::Invoiced->value)->whereNotNull('invoice_id')->get();
        $invoices = SalesInvoice::query()->whereIn('id', $statements->pluck('invoice_id'))->get()->keyBy('id');
        $groups = [];

        foreach ($statements as $statement) {
            $agreement = FranchiseAgreement::query()->find($statement->agreement_id);
            $scheme = match ($agreement?->payment_method) {
                PaymentMethod::SepaSdd => CollectionScheme::SepaSdd,
                PaymentMethod::LsvPlus => CollectionScheme::LsvPlus,
                default => null,
            };

            if ($scheme === null) {
                continue;
            }

            $invoice = $invoices->get($statement->invoice_id);
            $amount = Dec::round($invoice->total_gross ?? $statement->total_net);
            $excluded = empty($agreement->mandate_reference) ? 'mandate_missing' : null;

            $groups[$scheme->value][] = [
                'store_id' => (string) $statement->store_id,
                'store_code' => (string) $statement->store?->code,
                'invoice_id' => (string) $statement->invoice_id,
                'invoice_number' => $invoice?->invoice_number,
                'mandate_reference' => $agreement->mandate_reference,
                'mandate_signed_on' => $agreement->mandate_signed_on?->toDateString(),
                'amount' => $amount,
                'excluded_reason' => $excluded,
            ];
        }

        CollectionBatch::query()->where('run_id', $run->id)->forceDelete();
        $batches = [];

        foreach ($groups as $scheme => $items) {
            $collectable = array_filter($items, static fn (array $item): bool => $item['excluded_reason'] === null);
            $lead = (int) (config('franchise_network.billing.collection_lead_days')[$scheme] ?? 14);

            $batches[] = CollectionBatch::query()->create([
                'run_id' => $run->id,
                'scheme' => $scheme,
                'status' => $collectable === [] ? 'empty' : 'ready',
                'currency' => $run->currency,
                'total_amount' => Dec::round(array_reduce($collectable, static fn (string $sum, array $item): string => Dec::add($sum, $item['amount']), '0')),
                'collection_date' => CarbonImmutable::today()->addDays($lead)->toDateString(),
                'items' => $items,
            ]);
        }

        return $batches;
    }
}

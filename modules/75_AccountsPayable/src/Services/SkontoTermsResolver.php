<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use App\Models\Contact;
use App\Models\PaymentTerm;
use Illuminate\Support\Collection;
use Modules\AccountsPayable\DTOs\SkontoTerms;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Models\ApSupplierTerm;

/**
 * Finds the cash-discount terms of an open item, in this order: the terms set on the document itself, the active
 * agreement with the supplier valid on the document date (supplier master / contract terms), and the payment term of
 * the supplier contact (`discount_percent` within `discount_days`). An item with none of them has no Skonto.
 */
final class SkontoTermsResolver
{
    /**
     * @param  Collection<int, ApOpenItem>  $items
     * @return array<string, SkontoTerms|null> by open item id
     */
    public function forItems(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $supplierIds = $items->pluck('supplier_id')->unique()->values()->all();
        $agreements = ApSupplierTerm::query()
            ->whereIn('supplier_id', $supplierIds)
            ->where('is_active', true)
            ->orderByDesc('valid_from')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('supplier_id');
        $termIds = Contact::query()->whereIn('id', $supplierIds)->pluck('payment_term_id', 'id')->filter();
        $paymentTerms = $termIds->isEmpty() ? collect() : PaymentTerm::query()->whereIn('id', $termIds->unique()->values()->all())->get()->keyBy('id');

        $resolved = [];

        foreach ($items as $item) {
            $resolved[(string) $item->id] = $this->resolve($item, $agreements->get($item->supplier_id, collect()), $paymentTerms->get($termIds->get($item->supplier_id)));
        }

        return $resolved;
    }

    public function forItem(ApOpenItem $item): ?SkontoTerms
    {
        return $this->forItems(collect([$item]))[(string) $item->id] ?? null;
    }

    /**
     * @param  Collection<int, ApSupplierTerm>  $agreements
     */
    private function resolve(ApOpenItem $item, Collection $agreements, ?PaymentTerm $paymentTerm): ?SkontoTerms
    {
        if ($item->skonto_percentage !== null && $item->skonto_days !== null) {
            $override = new SkontoTerms((string) $item->skonto_percentage, (int) $item->skonto_days, null, SkontoTerms::SourceDocument);

            return $override->isUsable() ? $override : null;
        }

        /** @var ApSupplierTerm|null $agreement */
        $agreement = $agreements->first(static fn (ApSupplierTerm $term): bool => $term->appliesOn($item->document_date));

        if ($agreement !== null) {
            return new SkontoTerms((string) $agreement->skonto_percentage, (int) $agreement->skonto_days, $agreement->net_days, SkontoTerms::SourceSupplierAgreement, $agreement->reference);
        }

        if ($paymentTerm !== null && (int) $paymentTerm->discount_days > 0 && bccomp((string) ($paymentTerm->discount_percent ?? '0'), '0', 2) === 1) {
            return new SkontoTerms((string) $paymentTerm->discount_percent, (int) $paymentTerm->discount_days, (int) $paymentTerm->days > 0 ? (int) $paymentTerm->days : null, SkontoTerms::SourcePaymentTerm, (string) $paymentTerm->code);
        }

        return null;
    }
}

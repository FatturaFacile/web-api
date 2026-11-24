<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\MarketingAutomation\Models\Attribution;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\MarketingMessage;

/**
 * Attributes quotes, sales orders and invoices to the campaign that influenced them (First-Touch or Last-Touch inside
 * the attribution window of the campaign, or the coupon code of the campaign quoted on the document).
 */
final readonly class AttributionService
{
    public const array TYPES = ['quote' => 'quotes', 'sales_order' => 'sales_orders', 'invoice' => 'invoices'];

    public function __construct(
        private MetricsService $metrics,
        private CurrencyRateService $rates,
    ) {}

    public function attribute(string $tenantId, string $documentType, string $documentId): ?Attribution
    {
        $table = self::TYPES[$documentType] ?? null;
        if ($table === null) {
            return null;
        }

        $document = DB::table($table)->where('tenant_id', $tenantId)->where('id', $documentId)->whereNull('deleted_at')->first();
        if ($document === null || empty($document->contact_id)) {
            return null;
        }

        $existing = Attribution::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('document_type', $documentType)->where('document_id', $documentId)->first();
        if ($existing !== null) {
            return $this->refreshCollected($existing, $document);
        }

        $documentAt = Carbon::parse((string) ($document->date ?? $document->created_at ?? 'now'))->endOfDay();
        [$campaign, $message, $via] = $this->resolve($tenantId, (string) $document->contact_id, $documentAt, (string) ($document->notes ?? ''));
        if ($campaign === null) {
            return null;
        }

        $currency = (string) $campaign->currency;
        $rate = $this->rates->rate($tenantId, (string) ($document->currency ?: $currency), $currency);

        $attribution = Attribution::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'campaign_id' => $campaign->id,
            'message_id' => $message?->id,
            'contact_id' => $document->contact_id,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'touch_model' => $campaign->attribution_model,
            'currency' => $currency,
            'amount_net' => round((float) $document->total_net * $rate, 2),
            'amount_gross' => round((float) $document->total_gross * $rate, 2),
            'gross_margin' => $documentType === 'sales_order' ? round($this->orderMargin($tenantId, $documentId) * $rate, 2) : 0,
            'collected_amount' => $documentType === 'invoice' ? round((float) ($document->total_paid ?? 0) * $rate, 2) : 0,
            'via' => $via,
            'attributed_at' => Carbon::now(),
        ]);

        $this->metrics->recompute($campaign);

        return $attribution;
    }

    /**
     * Payments change after the invoice was attributed.
     */
    public function refreshCollected(Attribution $attribution, ?object $document = null): Attribution
    {
        if ($attribution->document_type !== 'invoice') {
            return $attribution;
        }

        $document ??= DB::table('invoices')->where('id', $attribution->document_id)->first();
        if ($document === null) {
            return $attribution;
        }

        $rate = $this->rates->rate((string) $attribution->tenant_id, (string) ($document->currency ?: $attribution->currency), (string) $attribution->currency);
        $collected = round((float) ($document->total_paid ?? 0) * $rate, 2);

        if ((string) $attribution->collected_amount !== number_format($collected, 2, '.', '')) {
            $attribution->forceFill(['collected_amount' => $collected])->save();
            $campaign = Campaign::withoutGlobalScopes()->find($attribution->campaign_id);
            if ($campaign !== null) {
                $this->metrics->recompute($campaign);
            }
        }

        return $attribution;
    }

    /**
     * @return array{0: Campaign|null, 1: MarketingMessage|null, 2: string}
     */
    private function resolve(string $tenantId, string $contactId, Carbon $documentAt, string $notes): array
    {
        $messages = MarketingMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('contact_id', $contactId)
            ->whereNotNull('sent_at')->where('sent_at', '<=', $documentAt)
            ->where(static fn ($q) => $q->whereNotNull('first_clicked_at')->orWhereNotNull('first_opened_at'))
            ->get();

        $candidates = [];
        foreach ($messages as $message) {
            $campaign = Campaign::withoutGlobalScopes()->find($message->campaign_id);
            if ($campaign === null) {
                continue;
            }
            $touchedAt = $message->first_clicked_at ?? $message->first_opened_at;
            if ($touchedAt === null || $touchedAt->copy()->addDays((int) $campaign->attribution_window_days)->lt($documentAt)) {
                continue;
            }
            $candidates[] = ['campaign' => $campaign, 'message' => $message, 'at' => $touchedAt];
        }

        if ($candidates !== []) {
            usort($candidates, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $models = array_unique(array_map(static fn (array $c): string => (string) $c['campaign']->attribution_model, $candidates));
            // Campaigns that disagree on the model fall back to the more common last touch.
            $pick = count($models) === 1 && $models[0] === 'first_touch' ? $candidates[0] : $candidates[array_key_last($candidates)];

            return [$pick['campaign'], $pick['message'], 'touch'];
        }

        // Coupon codes: the code of a campaign written on the document attributes it even without a tracked click.
        if ($notes !== '') {
            $haystack = mb_strtolower($notes);
            foreach (Campaign::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNotNull('coupon_code')->get() as $campaign) {
                if (str_contains($haystack, mb_strtolower((string) $campaign->coupon_code))) {
                    return [$campaign, null, 'coupon'];
                }
            }
        }

        return [null, null, 'touch'];
    }

    private function orderMargin(string $tenantId, string $orderId): float
    {
        $rows = DB::table('sales_order_items')->where('tenant_id', $tenantId)->where('sales_order_id', $orderId)->whereNull('deleted_at')
            ->get(['line_total', 'quantity', 'cost_price']);

        $margin = 0.0;
        foreach ($rows as $row) {
            $margin += (float) $row->line_total - (float) $row->quantity * (float) ($row->cost_price ?? 0);
        }

        return $margin;
    }
}

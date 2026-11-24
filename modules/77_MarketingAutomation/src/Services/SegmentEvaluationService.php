<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\MarketingAutomation\Domain\ValueObjects\RecipientContext;
use Modules\MarketingAutomation\Models\MarketingConsent;
use Modules\MarketingAutomation\Models\Segment;
use Modules\MarketingAutomation\Services\Privacy\PrivacyStrategyResolver;

/**
 * Builds the audience of a dynamic segment: RFM scores, business filters, credit semaphore exclusion (Mod 028) and
 * the consent rules of the jurisdiction (GDPR / nLPD / LCSl).
 */
final readonly class SegmentEvaluationService
{
    public const array LOCALES = ['it', 'de', 'fr', 'en'];

    public function __construct(
        private RfmScoringService $rfm,
        private InsolvencyGuard $insolvency,
        private SuppressionService $suppressions,
        private PrivacyStrategyResolver $strategies,
    ) {}

    /**
     * @return array{eligible: int, excluded_insolvent: int, excluded_no_consent: int, breakdown: array<string, int>, members: list<array<string, mixed>>}
     */
    public function compute(Segment $segment, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $tenantId = (string) $segment->tenant_id;
        $criteria = $segment->criteria ?? [];
        $tenantCountry = (string) (Tenant::query()->whereKey($tenantId)->value('country') ?? 'CH');

        $stats = $this->purchaseStats($tenantId);
        $frequencies = array_values(array_map(static fn (object $s): int => (int) $s->orders, $stats));
        $monetary = array_values(array_map(static fn (object $s): float => (float) $s->monetary, $stats));
        sort($frequencies);
        sort($monetary);

        $contacts = DB::table('contacts')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNull('anonymized_at')->whereNull('processing_restricted_at')
            ->whereIn('id', array_keys($stats))
            ->get(['id', 'country', 'email', 'document_language'])->keyBy('id');

        $consents = MarketingConsent::withoutGlobalScopes()->where('tenant_id', $tenantId)->get()->keyBy('contact_id');
        $blocked = ($criteria['exclude_insolvent'] ?? true) ? $this->insolvency->blockedContactIds($tenantId, (float) ($criteria['max_overdue_balance'] ?? 0)) : [];
        $suppressed = $this->suppressions->addressSet($tenantId);
        $categoryMatch = $this->contactsWithCategoryPurchases($tenantId, $criteria, $now);
        $regionMatch = $this->contactsInRegions($tenantId, $criteria);

        $members = [];
        $breakdown = [];
        $excludedInsolvent = 0;
        $excludedNoConsent = 0;

        foreach ($stats as $contactId => $stat) {
            $contact = $contacts->get($contactId);
            if ($contact === null) {
                continue;
            }

            $recencyDays = (int) Carbon::parse((string) $stat->last_date)->diffInDays($now, true);
            $orders = (int) $stat->orders;
            $value = (float) $stat->monetary;
            $r = $this->rfm->recencyScore($recencyDays);
            $f = $this->rfm->quantileScore($frequencies, $orders);
            $m = $this->rfm->quantileScore($monetary, $value);
            $label = $this->rfm->label($r, $f, $m);
            $locale = $this->locale($contact->document_language, $consents->get($contactId)?->locale);

            if (! $this->matchesBusinessFilters($criteria, $recencyDays, $orders, $value, $label->value, $locale, $contactId, $categoryMatch, $regionMatch)) {
                continue;
            }

            if (isset($blocked[$contactId])) {
                $excludedInsolvent++;

                continue;
            }

            $consent = $consents->get($contactId);
            $strategy = $this->strategies->forJurisdiction($contact->country, $tenantCountry);
            $address = (string) ($contact->email ?? '');
            $decision = $strategy->evaluate($consent, new RecipientContext(
                jurisdiction: strtoupper((string) ($contact->country ?: $tenantCountry)),
                locale: $locale,
                isExistingCustomer: true,
                boughtSimilarProducts: true,
                senderIdentified: true,
                addressSuppressed: $address === '' || isset($suppressed[$this->suppressions->normalise($address)]),
            ), 'email');

            if (! $decision->allowed || ! $strategy->allowsProfiling($consent)) {
                $excludedNoConsent++;

                continue;
            }

            $breakdown[$label->value] = ($breakdown[$label->value] ?? 0) + 1;
            $members[] = [
                'contact_id' => $contactId, 'r_score' => $r, 'f_score' => $f, 'm_score' => $m, 'rfm_label' => $label->value,
                'locale' => $locale, 'recency_days' => $recencyDays, 'frequency_orders' => $orders, 'monetary_value' => round($value, 2),
            ];
        }

        return [
            'eligible' => count($members),
            'excluded_insolvent' => $excludedInsolvent,
            'excluded_no_consent' => $excludedNoConsent,
            'breakdown' => $breakdown,
            'members' => $members,
        ];
    }

    /**
     * Computes and stores the memberships of a segment.
     */
    public function evaluate(Segment $segment, ?Carbon $now = null): Segment
    {
        $result = $this->compute($segment, $now);
        $tenantId = (string) $segment->tenant_id;
        $stamp = Carbon::now();

        DB::transaction(function () use ($segment, $result, $tenantId, $stamp): void {
            DB::table('ma_segment_members')->where('segment_id', $segment->id)->delete();

            foreach (array_chunk($result['members'], 500) as $chunk) {
                DB::table('ma_segment_members')->insert(array_map(static fn (array $row): array => [
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'segment_id' => $segment->id,
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ] + $row, $chunk));
            }

            $segment->forceFill([
                'eligible_contacts_count' => $result['eligible'],
                'excluded_insolvent_count' => $result['excluded_insolvent'],
                'excluded_no_consent_count' => $result['excluded_no_consent'],
                'rfm_breakdown' => $result['breakdown'],
                'last_evaluated_at' => $stamp,
            ])->save();
        });

        return $segment;
    }

    /**
     * @return array<string, object{last_date: string, orders: int|string, monetary: float|string}>
     */
    private function purchaseStats(string $tenantId): array
    {
        $rows = DB::table('invoices')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotNull('posted_at')
            ->groupBy('contact_id')
            ->selectRaw('contact_id, MAX(date) as last_date, COUNT(*) as orders, SUM(total_net) as monetary')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row->contact_id] = $row;
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, true>|null null = the filter is off
     */
    private function contactsWithCategoryPurchases(string $tenantId, array $criteria, Carbon $now): ?array
    {
        $categoryIds = array_values(array_filter((array) ($criteria['purchased_product_category_ids'] ?? [])));
        $skus = array_values(array_filter((array) ($criteria['purchased_skus'] ?? [])));
        if ($categoryIds === [] && $skus === []) {
            return null;
        }

        $query = DB::table('sales_orders as o')
            ->join('sales_order_items as i', 'i.sales_order_id', '=', 'o.id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('o.tenant_id', $tenantId)->whereNull('o.deleted_at')->whereNull('i.deleted_at');

        $query->where(static function ($q) use ($categoryIds, $skus): void {
            if ($categoryIds !== []) {
                $q->whereIn('p.category_id', $categoryIds);
            }
            if ($skus !== []) {
                $q->orWhereIn('p.sku', $skus);
            }
        });

        // "Bought machinery about 11 months ago": the order date lies between N and M months back.
        $window = (array) ($criteria['purchased_window_months'] ?? []);
        if (isset($window['min'])) {
            $query->where('o.date', '<=', $now->copy()->subMonths((int) $window['min'])->toDateString());
        }
        if (isset($window['max'])) {
            $query->where('o.date', '>=', $now->copy()->subMonths((int) $window['max'])->toDateString());
        }

        $set = [];
        foreach ($query->distinct()->pluck('o.contact_id') as $id) {
            $set[(string) $id] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, true>|null
     */
    private function contactsInRegions(string $tenantId, array $criteria): ?array
    {
        $regions = array_values(array_filter(array_map(static fn ($r): string => strtoupper(trim((string) $r)), (array) ($criteria['geographic_canton_or_province'] ?? []))));
        if ($regions === []) {
            return null;
        }

        $set = [];
        $ids = DB::table('contact_addresses')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->whereRaw('UPPER(state_canton) IN ('.implode(',', array_fill(0, count($regions), '?')).')', $regions)
            ->pluck('contact_id');
        foreach ($ids as $id) {
            $set[(string) $id] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, true>|null  $categoryMatch
     * @param  array<string, true>|null  $regionMatch
     */
    private function matchesBusinessFilters(array $c, int $recencyDays, int $orders, float $value, string $label, string $locale, string $contactId, ?array $categoryMatch, ?array $regionMatch): bool
    {
        return ! (
            (isset($c['recency_days_max']) && $recencyDays > (int) $c['recency_days_max'])
            || (isset($c['recency_days_min']) && $recencyDays < (int) $c['recency_days_min'])
            || (isset($c['frequency_min']) && $orders < (int) $c['frequency_min'])
            || (isset($c['frequency_max']) && $orders > (int) $c['frequency_max'])
            || (isset($c['monetary_min']) && $value < (float) $c['monetary_min'])
            || (isset($c['monetary_max']) && $value > (float) $c['monetary_max'])
            || (! empty($c['rfm_labels']) && ! in_array($label, (array) $c['rfm_labels'], true))
            || (! empty($c['locale']) && $c['locale'] !== $locale)
            || ($categoryMatch !== null && ! isset($categoryMatch[$contactId]))
            || ($regionMatch !== null && ! isset($regionMatch[$contactId]))
        );
    }

    private function locale(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $code = strtolower(substr((string) $candidate, 0, 2));
            if (in_array($code, self::LOCALES, true)) {
                return $code;
            }
        }

        return 'it';
    }
}

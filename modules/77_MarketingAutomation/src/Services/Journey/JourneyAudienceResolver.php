<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Journey;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\MarketingAutomation\Domain\Enums\TriggerType;
use Modules\MarketingAutomation\Models\Campaign;

/**
 * Who enters a journey: the contacts that fire the trigger of the campaign right now.
 */
final class JourneyAudienceResolver
{
    /**
     * @return list<string> contact ids
     */
    public function resolve(Campaign $campaign, Carbon $now): array
    {
        $tenantId = (string) $campaign->tenant_id;
        $trigger = TriggerType::tryFrom((string) $campaign->trigger_type) ?? TriggerType::ScheduledBroadcast;

        $ids = match ($trigger) {
            TriggerType::ScheduledBroadcast => $this->segmentMembers($campaign, $campaign->scheduled_at === null || $campaign->scheduled_at->lte($now)),
            TriggerType::CustomerDormant60Days => $this->dormant($campaign, $now),
            TriggerType::AbandonedCart => $this->abandonedCart($tenantId, $now),
            TriggerType::WarrantyExpiring => $this->warrantyExpiring($tenantId, $now),
            TriggerType::PostFairLeadFollowup => $this->postFairLeads($tenantId, $now),
        };

        // A segment narrows every trigger; without one the trigger decides alone (except for broadcasts).
        if ($campaign->segment_id !== null && $trigger !== TriggerType::ScheduledBroadcast) {
            $allowed = array_flip($this->segmentMembers($campaign, true));
            $ids = array_values(array_filter($ids, static fn (string $id): bool => isset($allowed[$id])));
        }

        $enrolled = array_flip(DB::table('ma_enrollments')->where('campaign_id', $campaign->id)->pluck('contact_id')->map(static fn ($v): string => (string) $v)->all());

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => ! isset($enrolled[$id]))));
    }

    /**
     * @return list<string>
     */
    private function segmentMembers(Campaign $campaign, bool $due): array
    {
        if (! $due || $campaign->segment_id === null) {
            return [];
        }

        return DB::table('ma_segment_members')->where('segment_id', $campaign->segment_id)->whereNull('deleted_at')->pluck('contact_id')->map(static fn ($v): string => (string) $v)->all();
    }

    /**
     * @return list<string>
     */
    private function dormant(Campaign $campaign, Carbon $now): array
    {
        $days = (int) ($campaign->template_mjml_json['dormant_days'] ?? config('marketing_automation.dormant_days', 60));

        return DB::table('invoices')
            ->where('tenant_id', $campaign->tenant_id)->whereNull('deleted_at')->whereNotNull('posted_at')
            ->groupBy('contact_id')
            ->havingRaw('MAX(date) <= ?', [$now->copy()->subDays($days)->toDateString()])
            ->pluck('contact_id')->map(static fn ($v): string => (string) $v)->all();
    }

    /**
     * Quotes that were sent and neither ordered nor closed for a while.
     *
     * @return list<string>
     */
    private function abandonedCart(string $tenantId, Carbon $now): array
    {
        return DB::table('quotes')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNull('converted_to_order_id')
            ->whereNotIn('status', ['draft', 'rejected', 'expired', 'cancelled', 'accepted', 'converted'])
            ->where('created_at', '<=', $now->copy()->subHours((int) config('marketing_automation.abandoned_cart_hours', 24)))
            ->where('created_at', '>=', $now->copy()->subDays(14))
            ->pluck('contact_id')->map(static fn ($v): string => (string) $v)->all();
    }

    /**
     * Equipment whose warranty ends within 30 days.
     *
     * @return list<string>
     */
    private function warrantyExpiring(string $tenantId, Carbon $now): array
    {
        return DB::table('esm_equipment_passports')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotNull('current_owner_customer_id')
            ->whereBetween('warranty_expiry_date', [$now->toDateString(), $now->copy()->addDays(30)->toDateString()])
            ->pluck('current_owner_customer_id')->map(static fn ($v): string => (string) $v)->all();
    }

    /**
     * Leads from fairs and events of the last 14 days that already became contacts.
     *
     * @return list<string>
     */
    private function postFairLeads(string $tenantId, Carbon $now): array
    {
        return DB::table('crm_leads as l')
            ->join('crm_lead_sources as s', 's.id', '=', 'l.lead_source_id')
            ->where('l.tenant_id', $tenantId)->whereNull('l.deleted_at')->whereNotNull('l.converted_contact_id')
            ->where('l.created_at', '>=', $now->copy()->subDays(14))
            ->where(static function ($q): void {
                $q->whereIn('s.type', ['fair', 'event', 'trade_show'])->orWhereIn('s.code', ['fair', 'event', 'trade_show']);
            })
            ->pluck('l.converted_contact_id')->map(static fn ($v): string => (string) $v)->all();
    }
}

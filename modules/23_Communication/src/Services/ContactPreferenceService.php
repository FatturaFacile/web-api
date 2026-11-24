<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use Modules\Communication\Contracts\ConsentLookup;
use Modules\Communication\Models\ContactChannelPreference;

/**
 * Per-channel opt-out of contacts, combined with the CRM consent. Marketing needs both: an explicit CRM opt-in and no
 * opt-out on the channel. Opting out is immediate and wins over any consent.
 */
final readonly class ContactPreferenceService
{
    public const string ReasonOptedOut = 'opted_out';

    public const string ReasonWithdrawn = 'consent_withdrawn';

    public const string ReasonNoConsent = 'no_consent';

    public function __construct(private ConsentLookup $consents) {}

    public function isOptedOut(string $tenantId, string $contactId, string $channel): bool
    {
        return ContactChannelPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('channel', $channel)
            ->where('is_opted_out', true)
            ->exists();
    }

    /**
     * Why a message must not be sent, or null when it may be sent.
     */
    public function blockReason(string $tenantId, ?string $contactId, ?string $leadId, string $channel, string $purpose): ?string
    {
        if ($contactId !== null && $this->isOptedOut($tenantId, $contactId, $channel)) {
            return self::ReasonOptedOut;
        }

        return match ($this->consents->status($contactId, $leadId, $channel, $purpose)) {
            'granted' => null,
            'withdrawn' => self::ReasonWithdrawn,
            default => self::ReasonNoConsent,
        };
    }

    public function set(string $tenantId, string $contactId, string $channel, bool $optedOut, ?string $reason, ?string $userId): ContactChannelPreference
    {
        $preference = ContactChannelPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('channel', $channel)
            ->first() ?? new ContactChannelPreference(['tenant_id' => $tenantId, 'contact_id' => $contactId, 'channel' => $channel]);

        $preference->fill(['is_opted_out' => $optedOut, 'reason' => $optedOut ? $reason : null, 'changed_at' => now(), 'changed_by' => $userId])->save();

        return $preference;
    }

    /**
     * Every channel of a contact with its opt-out and the effective marketing consent.
     *
     * @return list<array{channel: string, is_opted_out: bool, reason: ?string, changed_at: ?string, marketing_consent: ?string, can_receive_marketing: bool}>
     */
    public function overview(string $tenantId, string $contactId): array
    {
        $stored = ContactChannelPreference::query()->where('tenant_id', $tenantId)->where('contact_id', $contactId)->get()->keyBy('channel');
        $rows = [];

        foreach (ContactChannelPreference::Channels as $channel) {
            $preference = $stored->get($channel);
            $rows[] = [
                'channel' => $channel,
                'is_opted_out' => (bool) ($preference->is_opted_out ?? false),
                'reason' => $preference->reason ?? null,
                'changed_at' => $preference?->changed_at?->toIso8601String(),
                'marketing_consent' => $this->consents->status($contactId, null, $channel, 'marketing'),
                'can_receive_marketing' => $this->blockReason($tenantId, $contactId, null, $channel, 'marketing') === null,
            ];
        }

        return $rows;
    }
}

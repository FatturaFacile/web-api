<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\ContactConsent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Granular privacy consents. Each purpose keeps its latest decision together with the grant / revocation timestamps,
 * so the registry can prove when and where a consent was given. Swiss customers fall under nLPD, all others GDPR.
 */
class ContactConsentService
{
    /**
     * @return Collection<int, ContactConsent>
     */
    public function current(Contact $contact): Collection
    {
        return $contact->consents()->orderBy('purpose')->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function record(Contact $contact, array $items, ?string $userId = null): void
    {
        DB::transaction(function () use ($contact, $items, $userId): void {
            foreach ($items as $item) {
                $existing = $contact->consents()->where('purpose', $item['purpose'])->first();
                $granted = (bool) $item['granted'];
                $now = now();

                $changed = $existing === null || $existing->granted !== $granted;

                $attributes = [
                    'tenant_id' => $contact->tenant_id,
                    'regime' => $item['regime'] ?? $existing?->regime ?? ($contact->country === 'CH' ? 'nlpd' : 'gdpr'),
                    'granted' => $granted,
                    'source' => $item['source'] ?? $existing?->source,
                    'notes' => $item['notes'] ?? $existing?->notes,
                    'recorded_by_user_id' => $userId ?: $existing?->recorded_by_user_id,
                ];

                if ($changed) {
                    $attributes['granted_at'] = $granted ? $now : $existing?->granted_at;
                    $attributes['revoked_at'] = $granted ? null : ($existing === null ? null : $now);
                }

                $contact->consents()->updateOrCreate(['purpose' => $item['purpose']], $attributes);
            }
        });
    }

    public function hasConsent(Contact $contact, string $purpose): bool
    {
        return $contact->consents()->where('purpose', $purpose)->where('granted', true)->exists();
    }
}

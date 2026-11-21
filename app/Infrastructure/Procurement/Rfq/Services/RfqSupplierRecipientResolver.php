<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\Rfq\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContact;
use Modules\SupplierPortal\Models\SupplierOnboarding;

/**
 * Resolves supplier contact email and preferred language (IT/DE/FR/EN) for RFQ mail notifications.
 */
final class RfqSupplierRecipientResolver
{
    private const array SUPPORTED_LOCALES = ['it', 'en', 'de', 'fr'];

    /**
     * @return array{email: ?string, locale: string, supplier_name: string}
     */
    public function resolve(Supplier $supplier, ?Tenant $tenant = null): array
    {
        $primaryContactEmail = SupplierContact::query()
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('is_primary')
            ->whereNotNull('email')
            ->value('email');

        $contact = $supplier->contact_id !== null
            ? Contact::query()->withoutGlobalScopes()->find($supplier->contact_id)
            : Contact::query()->withoutGlobalScopes()->find($supplier->id);

        $onboardingEmail = SupplierOnboarding::query()
            ->where('supplier_id', $supplier->id)
            ->value('invitation_email');

        $email = $this->firstNonEmpty([
            is_string($primaryContactEmail) ? $primaryContactEmail : null,
            $contact?->email,
            is_string($onboardingEmail) ? $onboardingEmail : null,
        ]);

        $locale = $this->resolveLocale($supplier, $contact, $tenant);

        return [
            'email' => $email,
            'locale' => $locale,
            'supplier_name' => (string) $supplier->company_name,
        ];
    }

    /**
     * @param  list<string|null>  $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function resolveLocale(Supplier $supplier, ?Contact $contact, ?Tenant $tenant): string
    {
        $metaLocale = is_array($supplier->metadata) ? ($supplier->metadata['locale'] ?? $supplier->metadata['language'] ?? null) : null;
        if (is_string($metaLocale) && in_array(strtolower($metaLocale), self::SUPPORTED_LOCALES, true)) {
            return strtolower($metaLocale);
        }

        $country = strtoupper((string) ($contact->country ?? $tenant->country ?? 'IT'));

        return match ($country) {
            'DE', 'AT' => 'de',
            'FR', 'BE', 'LU' => 'fr',
            'IT', 'SM', 'VA' => 'it',
            'CH' => 'de',
            default => 'en',
        };
    }
}

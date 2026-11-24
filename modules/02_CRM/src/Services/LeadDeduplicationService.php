<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Support\Collection;
use Modules\CRM\Models\Lead;
use Modules\CRM\Support\LeadNormalizer;

/**
 * Looks for existing leads matching an incoming one on email, E.164 phone, VAT number or (fuzzy) company name.
 */
final class LeadDeduplicationService
{
    /**
     * @return Collection<int, Lead> matches, each tagged with a transient `match_reasons` attribute
     */
    public function findMatches(
        string $tenantId,
        ?string $email,
        ?string $phone,
        ?string $companyName,
        ?string $vatNumber = null,
        string $country = 'IT',
        ?string $excludeLeadId = null,
    ): Collection {
        $email = LeadNormalizer::email($email);
        $phoneE164 = LeadNormalizer::phoneE164($phone, $country);
        $vat = LeadNormalizer::vat($vatNumber);
        $company = LeadNormalizer::company($companyName);

        if ($email === null && $phoneE164 === null && $vat === null && $company === null) {
            return collect();
        }

        $query = Lead::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['converted', 'archived'])
            ->when($excludeLeadId, fn ($q) => $q->where('id', '!=', $excludeLeadId))
            ->where(function ($q) use ($email, $phoneE164, $vat, $company): void {
                if ($email !== null) {
                    $q->orWhere('email', $email);
                }
                if ($phoneE164 !== null) {
                    $q->orWhere('phone_e164', $phoneE164);
                }
                if ($vat !== null) {
                    $q->orWhere('vat_number', $vat);
                }
                if ($company !== null) {
                    // Prefilter on the first characters; the exact distance is computed in PHP below.
                    $q->orWhere('company_normalized', 'like', substr($company, 0, 3).'%');
                }
            });

        $matches = collect();

        foreach ($query->oldest()->get() as $candidate) {
            $reasons = [];

            if ($email !== null && $candidate->email === $email) {
                $reasons[] = 'email';
            }
            if ($phoneE164 !== null && $candidate->phone_e164 === $phoneE164) {
                $reasons[] = 'phone';
            }
            if ($vat !== null && $candidate->vat_number === $vat) {
                $reasons[] = 'vat_number';
            }
            if ($company !== null && $candidate->company_normalized !== null
                && LeadNormalizer::similarity($company, $candidate->company_normalized) >= (float) config('crm_lead.company_similarity', 0.88)) {
                $reasons[] = 'company_name';
            }

            if ($reasons !== []) {
                $candidate->setAttribute('match_reasons', $reasons);
                $matches->push($candidate);
            }
        }

        return $matches;
    }

    /**
     * Fills the empty fields of $primary with the incoming data and accumulates behavioural counters.
     *
     * @param  array<string, mixed>  $incoming  normalized lead attributes
     * @return array<string, mixed> attributes to persist on the primary lead
     */
    public function mergeAttributes(Lead $primary, array $incoming): array
    {
        $updates = [];

        foreach (['first_name', 'last_name', 'company_name', 'title', 'phone', 'phone_e164', 'website', 'vat_number',
            'company_normalized', 'industry', 'region', 'product_line', 'company_size', 'estimated_revenue', 'estimated_budget', 'email'] as $field) {
            if (($primary->{$field} === null || $primary->{$field} === '') && ! empty($incoming[$field])) {
                $updates[$field] = $incoming[$field];
            }
        }

        foreach (['email_opens', 'catalog_downloads', 'site_visits'] as $counter) {
            if (! empty($incoming[$counter])) {
                $updates[$counter] = (int) $primary->{$counter} + (int) $incoming[$counter];
            }
        }

        $updates['tags'] = array_values(array_unique(array_merge($primary->tags ?? [], $incoming['tags'] ?? [])));
        $updates['custom_fields'] = array_merge($incoming['custom_fields'] ?? [], $primary->custom_fields ?? []);

        return $updates;
    }
}

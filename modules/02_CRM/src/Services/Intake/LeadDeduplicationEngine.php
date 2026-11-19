<?php

declare(strict_types=1);

namespace Modules\CRM\Services\Intake;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Modules\CRM\Contracts\LeadDeduplicationServiceInterface;
use Modules\CRM\Contracts\LeadNormalizerInterface;
use Modules\CRM\DTOs\DuplicateMatch;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\ContactRole;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadDuplicateCandidate;
use Modules\CRM\Models\LeadSetting;

final readonly class LeadDeduplicationEngine implements LeadDeduplicationServiceInterface
{
    /**
     * Upper bound of leads loaded for the in-memory fuzzy company comparison.
     */
    private const int FUZZY_SCAN_LIMIT = 500;

    /**
     * Names shorter than this are too generic for fuzzy matching.
     */
    private const int FUZZY_MIN_LENGTH = 4;

    /**
     * SQL expression normalizing a stored VAT number the same way LeadNormalizer::normalizeVat() does
     * for the separators used in practice (spaces, dots, dashes, slashes).
     */
    private const string VAT_SQL = "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(vat_number, ' ', ''), '.', ''), '-', ''), '/', ''))";

    public function __construct(
        private LeadNormalizerInterface $normalizer,
    ) {}

    public function findMatches(string $tenantId, array $identifiers, ?string $excludeLeadId = null): array
    {
        $email = $this->normalizer->normalizeEmail($identifiers['email'] ?? null);
        $phone = $identifiers['phone_e164'] ?? $this->normalizer->normalizePhone($identifiers['phone'] ?? null, $identifiers['country'] ?? null);
        $vatVariants = $this->normalizer->vatVariants($identifiers['vat_number'] ?? null);
        $company = $this->normalizer->normalizeCompanyName($identifiers['company_name'] ?? null);

        /** @var array<string, DuplicateMatch> $matches keyed by "type:id" so each record appears once */
        $matches = [];

        $this->collectExactLeadMatches($tenantId, $email, $phone, $vatVariants, $excludeLeadId, $matches);
        $contactIds = $this->collectContactMatches($tenantId, $email, $vatVariants, $matches);
        $this->collectAccountMatches($tenantId, $contactIds, $matches);

        if ($company !== null) {
            $this->collectFuzzyLeadMatches($tenantId, $company, $excludeLeadId, $matches);
        }

        return array_values($matches);
    }

    public function findMatchesForLead(Lead $lead): array
    {
        return $this->findMatches((string) $lead->tenant_id, [
            'email' => $lead->email,
            'phone' => $lead->phone,
            'phone_e164' => $lead->phone_e164,
            'vat_number' => $lead->vat_number,
            'company_name' => $lead->company_name,
            'country' => $lead->country,
        ], (string) $lead->id);
    }

    public function recordCandidates(Lead $lead, array $matches): array
    {
        $created = [];

        foreach ($matches as $match) {
            $column = match ($match->recordType) {
                'lead' => 'candidate_lead_id',
                'contact' => 'contact_id',
                default => 'account_id',
            };

            $candidate = LeadDuplicateCandidate::query()
                ->where('tenant_id', $lead->tenant_id)
                ->where('lead_id', $lead->id)
                ->where($column, $match->recordId)
                ->where('match_type', $match->matchType)
                ->first();

            if ($candidate instanceof LeadDuplicateCandidate) {
                continue;
            }

            $created[] = LeadDuplicateCandidate::create([
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                $column => $match->recordId,
                'match_type' => $match->matchType,
                'confidence' => round($match->confidence, 4),
                'status' => 'pending',
            ]);
        }

        return $created;
    }

    public function findContactForLead(Lead $lead): ?Contact
    {
        $tenantId = (string) $lead->tenant_id;
        $vatVariants = $this->normalizer->vatVariants($lead->vat_number);

        if ($vatVariants !== []) {
            $placeholders = implode(', ', array_fill(0, count($vatVariants), '?'));
            $contact = Contact::query()
                ->where('tenant_id', $tenantId)
                ->whereRaw(self::VAT_SQL." IN ({$placeholders})", $vatVariants)
                ->orderBy('created_at')
                ->first();

            if ($contact instanceof Contact) {
                return $contact;
            }
        }

        $email = $this->normalizer->normalizeEmail($lead->email);
        if ($email === null) {
            return null;
        }

        $contact = Contact::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orderBy('created_at')
            ->first();

        return $contact instanceof Contact ? $contact : null;
    }

    public function findAccountForLead(Lead $lead): ?Account
    {
        $tenantId = (string) $lead->tenant_id;
        $contactMatches = [];
        $contactIds = $this->collectContactMatches(
            $tenantId,
            $this->normalizer->normalizeEmail($lead->email),
            $this->normalizer->vatVariants($lead->vat_number),
            $contactMatches,
        );

        if ($contactIds !== []) {
            $account = Account::query()->where('tenant_id', $tenantId)->whereIn('contact_id', $contactIds)->first();
            if ($account instanceof Account) {
                return $account;
            }

            $accountId = ContactRole::query()->where('tenant_id', $tenantId)->whereIn('contact_id', $contactIds)->value('account_id');
            if ($accountId !== null) {
                $account = Account::query()->where('tenant_id', $tenantId)->find($accountId);
                if ($account instanceof Account) {
                    return $account;
                }
            }
        }

        $company = $this->normalizer->normalizeCompanyName($lead->company_name);
        if ($company === null || empty($lead->company_name)) {
            return null;
        }

        // Exact company-name match only: fuzzy matches are never used to link records automatically.
        $firstToken = explode(' ', $company)[0];

        return Account::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$firstToken.'%'])
            ->limit(50)
            ->get()
            ->first(fn (Account $account): bool => $this->normalizer->normalizeCompanyName((string) $account->name) === $company);
    }

    public function compareCompanyNames(string $normalizedA, string $normalizedB): array
    {
        if ($normalizedA === $normalizedB) {
            return [0, 1.0];
        }

        $distance = levenshtein($normalizedA, $normalizedB);
        $maxLength = max(strlen($normalizedA), strlen($normalizedB));
        $similarity = $maxLength > 0 ? 1 - ($distance / $maxLength) : 0.0;

        similar_text($normalizedA, $normalizedB, $percent);
        $similarity = max($similarity, $percent / 100);

        return [$distance, round($similarity, 4)];
    }

    /**
     * @param  list<string>  $vatVariants
     * @param  array<string, DuplicateMatch>  $matches
     */
    private function collectExactLeadMatches(string $tenantId, ?string $email, ?string $phone, array $vatVariants, ?string $excludeLeadId, array &$matches): void
    {
        if ($email === null && $phone === null && $vatVariants === []) {
            return;
        }

        $leads = $this->activeLeads($tenantId, $excludeLeadId)
            ->where(function (Builder $query) use ($email, $phone, $vatVariants): void {
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }
                if ($phone !== null) {
                    $query->orWhere('phone_e164', $phone);
                }
                if ($vatVariants !== []) {
                    $query->orWhereIn('vat_number', $vatVariants);
                }
            })
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        foreach ($leads as $lead) {
            $matchType = match (true) {
                $vatVariants !== [] && in_array((string) $lead->vat_number, $vatVariants, true) => 'vat',
                $email !== null && $lead->email === $email => 'email',
                default => 'phone',
            };

            $matches['lead:'.$lead->id] = new DuplicateMatch('lead', (string) $lead->id, $matchType, 1.0);
        }
    }

    /**
     * @param  list<string>  $vatVariants
     * @param  array<string, DuplicateMatch>  $matches
     * @return list<string> matched contact ids
     */
    private function collectContactMatches(string $tenantId, ?string $email, array $vatVariants, array &$matches): array
    {
        if ($email === null && $vatVariants === []) {
            return [];
        }

        $contacts = Contact::query()
            ->where('tenant_id', $tenantId)
            ->where(function (Builder $query) use ($email, $vatVariants): void {
                if ($email !== null) {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if ($vatVariants !== []) {
                    $placeholders = implode(', ', array_fill(0, count($vatVariants), '?'));
                    $query->orWhereRaw(self::VAT_SQL." IN ({$placeholders})", $vatVariants);
                }
            })
            ->limit(20)
            ->get();

        $ids = [];
        foreach ($contacts as $contact) {
            $contactVat = $this->normalizer->normalizeVat($contact->vat_number);
            $matchType = $contactVat !== null && in_array($contactVat, $vatVariants, true) ? 'vat' : 'email';
            $matches['contact:'.$contact->id] = new DuplicateMatch('contact', (string) $contact->id, $matchType, 1.0);
            $ids[] = (string) $contact->id;
        }

        return $ids;
    }

    /**
     * @param  list<string>  $contactIds
     * @param  array<string, DuplicateMatch>  $matches
     */
    private function collectAccountMatches(string $tenantId, array $contactIds, array &$matches): void
    {
        if ($contactIds === []) {
            return;
        }

        $accounts = Account::query()->where('tenant_id', $tenantId)->whereIn('contact_id', $contactIds)->limit(20)->get();

        foreach ($accounts as $account) {
            $contactMatch = $matches['contact:'.$account->contact_id] ?? null;
            $matches['account:'.$account->id] = new DuplicateMatch('account', (string) $account->id, $contactMatch !== null ? $contactMatch->matchType : 'email', 1.0);
        }
    }

    /**
     * @param  array<string, DuplicateMatch>  $matches
     */
    private function collectFuzzyLeadMatches(string $tenantId, string $company, ?string $excludeLeadId, array &$matches): void
    {
        if (strlen($company) < self::FUZZY_MIN_LENGTH) {
            return;
        }

        $settings = LeadSetting::forTenant($tenantId);
        $maxDistance = max(0, (int) $settings->dedup_levenshtein_max);
        $minSimilarity = (float) $settings->dedup_similarity_min;

        $length = strlen($company);
        $window = max($maxDistance, (int) ceil($length * (1 - $minSimilarity)));

        $leads = $this->activeLeads($tenantId, $excludeLeadId)
            ->whereNotNull('company_name_normalized')
            ->whereRaw('LENGTH(company_name_normalized) BETWEEN ? AND ?', [max(1, $length - $window), $length + $window])
            ->orderByDesc('created_at')
            ->limit(self::FUZZY_SCAN_LIMIT)
            ->get(['id', 'company_name_normalized']);

        foreach ($leads as $lead) {
            $key = 'lead:'.$lead->id;
            if (isset($matches[$key])) {
                continue;
            }

            $candidate = (string) $lead->company_name_normalized;
            if (strlen($candidate) < self::FUZZY_MIN_LENGTH) {
                continue;
            }

            [$distance, $similarity] = $this->compareCompanyNames($company, $candidate);

            if ($distance <= $maxDistance || $similarity >= $minSimilarity) {
                $matches[$key] = new DuplicateMatch('lead', (string) $lead->id, 'company_fuzzy', $similarity);
            }
        }
    }

    /**
     * @return Builder<Lead>
     */
    private function activeLeads(string $tenantId, ?string $excludeLeadId): Builder
    {
        return Lead::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('merged_into_lead_id')
            ->where('status', '!=', 'converted')
            ->when($excludeLeadId !== null, fn (Builder $query) => $query->where('id', '!=', $excludeLeadId));
    }
}

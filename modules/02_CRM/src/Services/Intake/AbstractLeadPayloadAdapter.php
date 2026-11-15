<?php

declare(strict_types=1);

namespace Modules\CRM\Services\Intake;

use Illuminate\Http\Request;
use Modules\CRM\Contracts\LeadPayloadAdapter;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\Models\LeadSetting;
use Modules\CRM\Models\LeadSource;

/**
 * Shared verification (timestamped HMAC, browser API key) and field canonicalization for intake adapters.
 * Subclasses only describe where the provider puts the fields and the external id.
 */
abstract class AbstractLeadPayloadAdapter implements LeadPayloadAdapter
{
    public const string HONEYPOT_FIELD = '_hp';

    /**
     * Canonical lead attribute => accepted (normalized) incoming field names.
     *
     * @var array<string, list<string>>
     */
    private const array ALIASES = [
        'email' => ['email', 'e_mail', 'email_address', 'your_email', 'mail'],
        'phone' => ['phone', 'phone_number', 'telephone', 'tel', 'mobile', 'mobile_phone', 'your_phone', 'telefono', 'cellulare'],
        'first_name' => ['first_name', 'firstname', 'given_name', 'nome'],
        'last_name' => ['last_name', 'lastname', 'surname', 'family_name', 'cognome'],
        'full_name' => ['full_name', 'fullname', 'name', 'your_name', 'nome_completo'],
        'company_name' => ['company_name', 'company', 'companyname', 'organization', 'organisation', 'azienda', 'ragione_sociale'],
        'title' => ['title', 'job_title', 'jobtitle', 'position', 'role', 'ruolo'],
        'website' => ['website', 'web_site', 'url', 'company_website', 'sito'],
        'country' => ['country', 'country_code', 'nazione', 'paese'],
        'vat_number' => ['vat_number', 'vat', 'vat_id', 'piva', 'p_iva', 'partita_iva', 'uid', 'uid_number'],
        'industry' => ['industry', 'sector', 'settore'],
        'employee_count' => ['employee_count', 'employees', 'company_size', 'number_of_employees', 'dipendenti'],
        'annual_revenue_estimate' => ['annual_revenue_estimate', 'annual_revenue', 'revenue', 'fatturato'],
        'state_region' => ['state_region', 'state', 'region', 'province', 'provincia', 'canton', 'cantone'],
        'product_line' => ['product_line', 'product', 'product_interest', 'interest', 'prodotto'],
        'estimated_budget' => ['estimated_budget', 'budget'],
    ];

    /**
     * Maximum lengths matching the crm_leads columns.
     *
     * @var array<string, int>
     */
    private const array MAX_LENGTHS = [
        'email' => 150,
        'phone' => 50,
        'first_name' => 100,
        'last_name' => 100,
        'company_name' => 200,
        'title' => 100,
        'website' => 255,
        'vat_number' => 30,
        'industry' => 100,
        'state_region' => 50,
        'product_line' => 100,
    ];

    /**
     * Extracts the flat "field name => value" map from the provider payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    abstract protected function extractFields(array $payload, LeadSource $source): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    abstract protected function extractExternalId(array $payload): ?string;

    public function verify(Request $request, LeadSource $source): bool
    {
        return $this->verifyTimestampedSignature($request, $source) || $this->verifyBrowserApiKey($request, $source);
    }

    public function toDto(array $payload, LeadSource $source): CreateLeadDTO
    {
        $fields = $this->canonicalize($this->extractFields($payload, $source), $source);
        $settings = LeadSetting::forTenant((string) $source->tenant_id);

        [$firstName, $lastName] = $this->resolveNames($fields);

        $country = strtoupper((string) ($fields['country'] ?? ''));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $country = (string) $settings->default_country;
        }

        $externalId = $this->extractExternalId($payload);

        return new CreateLeadDTO(
            tenantId: (string) $source->tenant_id,
            companyId: (string) $source->tenant_id,
            firstName: $this->text($firstName, 'first_name'),
            lastName: $this->text($lastName, 'last_name'),
            companyName: $this->text($fields['company_name'] ?? null, 'company_name'),
            email: $this->text($fields['email'] ?? null, 'email'),
            phone: $this->text($fields['phone'] ?? null, 'phone'),
            title: $this->text($fields['title'] ?? null, 'title'),
            website: $this->text($fields['website'] ?? null, 'website'),
            country: $country,
            leadSourceCode: (string) $source->code,
            estimatedBudget: $this->number($fields['estimated_budget'] ?? null),
            tags: [(string) ($source->type ?: 'web_form')],
            customFields: $fields['_custom'] ?? [],
            vatNumber: $this->text($fields['vat_number'] ?? null, 'vat_number'),
            industry: $this->text($fields['industry'] ?? null, 'industry'),
            employeeCount: $this->integer($fields['employee_count'] ?? null),
            annualRevenueEstimate: $this->number($fields['annual_revenue_estimate'] ?? null),
            stateRegion: $this->text($fields['state_region'] ?? null, 'state_region'),
            productLine: $this->text($fields['product_line'] ?? null, 'product_line') ?? $this->text($source->default_product_line, 'product_line'),
            externalId: $externalId !== null ? mb_substr($externalId, 0, 191) : null,
            leadSourceId: (string) $source->id,
        );
    }

    /**
     * Generic signature: X-Signature: sha256=<hex hmac("{timestamp}.{raw body}")>, X-Timestamp within tolerance.
     */
    protected function verifyTimestampedSignature(Request $request, LeadSource $source): bool
    {
        $signature = (string) $request->header('X-Signature', '');
        $timestamp = (string) $request->header('X-Timestamp', '');
        $secret = (string) $source->webhook_secret;

        if ($signature === '' || $secret === '' || ! $this->isFreshTimestamp($timestamp)) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, strtolower($signature));
    }

    /**
     * Plain HMAC of the raw body (Meta / LinkedIn style), with an optional "<prefix>=" in the header value.
     */
    protected function verifyBodySignature(Request $request, LeadSource $source, string $header, string $prefix): bool
    {
        $signature = strtolower(trim((string) $request->header($header, '')));
        $secret = (string) $source->webhook_secret;

        if ($signature === '' || $secret === '') {
            return false;
        }

        if (str_starts_with($signature, $prefix.'=')) {
            $signature = substr($signature, strlen($prefix) + 1);
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    /**
     * Simple browser forms: X-Api-Key + Origin listed in allowed_origins + empty honeypot field.
     */
    protected function verifyBrowserApiKey(Request $request, LeadSource $source): bool
    {
        $apiKey = (string) $request->header('X-Api-Key', '');
        if ($apiKey === '' || ! $source->verifyApiKey($apiKey)) {
            return false;
        }

        $origin = rtrim(strtolower((string) $request->header('Origin', '')), '/');
        $allowed = array_map(
            static fn (mixed $value): string => rtrim(strtolower(trim((string) $value)), '/'),
            is_array($source->allowed_origins) ? $source->allowed_origins : [],
        );

        if ($origin === '' || ! in_array($origin, $allowed, true)) {
            return false;
        }

        $honeypot = $request->input(self::HONEYPOT_FIELD);

        return $honeypot === null || (is_string($honeypot) && trim($honeypot) === '');
    }

    protected function isFreshTimestamp(string $timestamp, int $divisor = 1): bool
    {
        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) config('crm.lead_webhook.timestamp_tolerance_seconds', 300);
        $seconds = intdiv((int) $timestamp, $divisor);

        return abs(now()->getTimestamp() - $seconds) <= $tolerance;
    }

    /**
     * Turns a list of {name|label|key, value|values|answer} items into a "name => value" map.
     *
     * @param  array<int|string, mixed>  $items
     * @return array<string, mixed>
     */
    protected function flattenFieldList(array $items): array
    {
        if (! array_is_list($items)) {
            return $items;
        }

        $fields = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $item['name'] ?? $item['label'] ?? $item['key'] ?? $item['question'] ?? $item['id'] ?? null;
            if (! is_scalar($name) || (string) $name === '') {
                continue;
            }

            $value = $item['value'] ?? $item['values'] ?? $item['answer'] ?? null;
            if (is_array($value)) {
                $value = array_is_list($value) ? implode(', ', array_filter($value, 'is_scalar')) : null;
            }

            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    /**
     * Applies the tenant field_mapping, then folds known aliases into canonical attribute names.
     * Unknown scalar fields are kept under "_custom".
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function canonicalize(array $raw, LeadSource $source): array
    {
        $normalized = [];
        foreach ($raw as $key => $value) {
            if ($key === self::HONEYPOT_FIELD) {
                continue;
            }
            $normalized[$this->normalizeKey((string) $key)] = $value;
        }

        $mapping = is_array($source->field_mapping) ? $source->field_mapping : [];
        foreach ($mapping as $from => $to) {
            $fromKey = $this->normalizeKey((string) $from);
            if (is_string($to) && $to !== '' && array_key_exists($fromKey, $normalized)) {
                $normalized[$this->normalizeKey($to)] = $normalized[$fromKey];
                if ($fromKey !== $this->normalizeKey($to)) {
                    unset($normalized[$fromKey]);
                }
            }
        }

        $canonical = [];
        $consumed = [];
        foreach (self::ALIASES as $attribute => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $normalized) && $this->filled($normalized[$alias])) {
                    $canonical[$attribute] = $normalized[$alias];
                    $consumed[$alias] = true;
                    break;
                }
            }
        }

        $custom = [];
        foreach ($normalized as $key => $value) {
            if (isset($consumed[$key]) || ! is_scalar($value) || count($custom) >= 50) {
                continue;
            }
            $custom[$key] = is_string($value) ? mb_substr($value, 0, 1000) : $value;
        }
        $canonical['_custom'] = $custom;

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveNames(array $fields): array
    {
        $first = $this->scalarString($fields['first_name'] ?? null);
        $last = $this->scalarString($fields['last_name'] ?? null);
        $full = $this->scalarString($fields['full_name'] ?? null);

        if ($first === null && $last === null && $full !== null) {
            $parts = preg_split('/\s+/', $full, 2) ?: [];
            $first = $parts[0] ?? null;
            $last = $parts[1] ?? null;
        }

        return [$first, $last];
    }

    private function normalizeKey(string $key): string
    {
        // camelCase -> snake_case without splitting acronyms ("VAT" stays "vat").
        $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($key));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function scalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function text(mixed $value, string $attribute): ?string
    {
        $string = $this->scalarString($value);
        if ($string === null) {
            return null;
        }

        $max = self::MAX_LENGTHS[$attribute] ?? 255;

        return mb_substr($string, 0, $max);
    }

    private function number(mixed $value): ?float
    {
        $string = $this->scalarString($value);
        if ($string === null) {
            return null;
        }

        $clean = (string) preg_replace('/[^\d.,]/', '', $string);
        // "1.234,56" / "1,234.56" / "1234,56": keep the last separator as decimal point.
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $clean, $matches) === 1) {
            $clean = preg_replace('/[.,]/', '', $matches[1]).'.'.$matches[2];
        } else {
            $clean = (string) preg_replace('/[.,]/', '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function integer(mixed $value): ?int
    {
        $string = $this->scalarString($value);
        if ($string === null) {
            return null;
        }

        // Ranges such as "51-200" or "SIZE_51_TO_200" keep their lower bound.
        if (preg_match('/\d+/', str_replace(['.', ','], '', $string), $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }
}

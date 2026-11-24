<?php

declare(strict_types=1);

namespace Modules\CRM\Support;

/**
 * Maps heterogeneous Web-to-Lead payloads (WordPress/Elementor/CF7, Webflow, Meta Ads, LinkedIn Lead Gen,
 * generic JSON) to canonical lead fields. Keys are matched case/punctuation-insensitively through an alias table,
 * and an endpoint-level `field_map` ({"source_key": "canonical_key"}) takes precedence.
 */
final class LeadPayloadMapper
{
    private const ALIASES = [
        'email' => ['email', 'emailaddress', 'workemail', 'businessemail', 'mail', 'youremail', 'emailaziendale'],
        'first_name' => ['firstname', 'fname', 'givenname', 'nome', 'vorname', 'prenom'],
        'last_name' => ['lastname', 'lname', 'surname', 'familyname', 'cognome', 'nachname', 'nom'],
        'full_name' => ['fullname', 'name', 'yourname', 'contactname', 'nomecognome', 'nominativo'],
        'company_name' => ['company', 'companyname', 'organization', 'organisation', 'azienda', 'ragionesociale', 'firma', 'societe'],
        'phone' => ['phone', 'phonenumber', 'tel', 'telephone', 'telefono', 'mobile', 'cellulare', 'cell'],
        'title' => ['title', 'jobtitle', 'role', 'ruolo', 'position', 'funzione'],
        'website' => ['website', 'url', 'sitoweb', 'web', 'companywebsite'],
        'vat_number' => ['vat', 'vatnumber', 'partitaiva', 'piva', 'uid', 'mwst', 'taxid'],
        'country' => ['country', 'paese', 'nazione', 'land', 'countrycode'],
        'region' => ['region', 'regione', 'canton', 'cantone', 'state', 'province', 'provincia'],
        'industry' => ['industry', 'sector', 'settore', 'branche', 'categoriamerceologica'],
        'product_line' => ['productline', 'product', 'prodotto', 'lineaprodotto', 'interest', 'interesse'],
        'company_size' => ['companysize', 'employees', 'dipendenti', 'numerodipendenti', 'headcount'],
        'estimated_revenue' => ['revenue', 'fatturato', 'annualrevenue', 'fatturatostimato'],
        'estimated_budget' => ['budget', 'estimatedbudget', 'budgetstimato'],
        'event' => ['event', 'evento', 'behavior', 'behaviour'],
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>|null  $fieldMap
     * @return array<string, mixed> canonical fields (only the ones found)
     */
    public static function map(array $payload, ?array $fieldMap = null): array
    {
        $flat = [];
        self::flatten($payload, $flat);

        $custom = [];
        foreach (($fieldMap ?? []) as $source => $target) {
            $custom[self::key((string) $source)] = (string) $target;
        }

        $aliases = [];
        foreach (self::ALIASES as $canonical => $list) {
            foreach ($list as $alias) {
                $aliases[$alias] = $canonical;
            }
        }

        $out = [];
        $extra = [];

        foreach ($flat as $key => $value) {
            $target = $custom[$key] ?? $aliases[$key] ?? null;

            if ($target === null) {
                $extra[$key] = $value;

                continue;
            }

            // The first non-empty value for a canonical field wins ("name" must not override "first_name").
            if (! isset($out[$target]) || $out[$target] === '') {
                $out[$target] = $value;
            }
        }

        if (! empty($out['full_name'])) {
            $parts = preg_split('/\s+/', trim((string) $out['full_name']), 2) ?: [];
            $out['first_name'] ??= $parts[0] ?? null;
            $out['last_name'] ??= $parts[1] ?? null;
        }
        unset($out['full_name']);

        foreach (['company_size'] as $int) {
            if (isset($out[$int])) {
                $out[$int] = (int) preg_replace('/\D+/', '', (string) $out[$int]);
            }
        }
        foreach (['estimated_revenue', 'estimated_budget'] as $money) {
            if (isset($out[$money])) {
                $out[$money] = (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', (string) $out[$money]) ?? '');
            }
        }
        if (isset($out['country'])) {
            $out['country'] = strtoupper(substr(trim((string) $out['country']), 0, 2));
        }

        $out['extra'] = $extra;

        return $out;
    }

    private static function key(string $name): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>  $flat
     */
    private static function flatten(array $data, array &$flat): void
    {
        // Meta Ads / LinkedIn style: [{"name":"email","values":["a@b.c"]}] or [{"key":..,"value":..}]
        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item) && isset($item['name']) && (isset($item['values']) || isset($item['value']))) {
                    $value = $item['value'] ?? (is_array($item['values']) ? ($item['values'][0] ?? null) : $item['values']);
                    self::put($flat, (string) $item['name'], $value);
                } elseif (is_array($item) && isset($item['key'], $item['value'])) {
                    self::put($flat, (string) $item['key'], $item['value']);
                } elseif (is_array($item)) {
                    self::flatten($item, $flat);
                }
            }

            return;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::flatten($value, $flat);
            } else {
                self::put($flat, (string) $key, $value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $flat
     */
    private static function put(array &$flat, string $key, mixed $value): void
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return;
        }

        $flat[self::key($key)] ??= is_string($value) ? trim($value) : $value;
    }
}

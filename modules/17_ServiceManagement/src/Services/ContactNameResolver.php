<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use App\Models\Contact;

/**
 * Display name of a CRM contact: company name, or "first last" for private customers.
 */
final class ContactNameResolver
{
    public static function nameOf(?Contact $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        $company = trim((string) ($contact->company_name ?? ''));
        if ($company !== '') {
            return $company;
        }

        $person = trim(((string) ($contact->first_name ?? '')).' '.((string) ($contact->last_name ?? '')));

        return $person !== '' ? $person : null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Support;

use App\Models\Contact;

/**
 * Display name of a contact (company name, else first and last name).
 */
final class ContactName
{
    public static function of(?Contact $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        $name = trim((string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name)));

        return $name === '' ? null : $name;
    }
}

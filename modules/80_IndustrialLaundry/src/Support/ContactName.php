<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Support;

use App\Models\Contact;

final class ContactName
{
    /**
     * @return array{id: string, name: string, country: string|null}|null
     */
    public static function summary(?Contact $contact): ?array
    {
        if ($contact === null) {
            return null;
        }

        return [
            'id' => (string) $contact->id,
            'name' => $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')),
            'country' => $contact->country,
        ];
    }
}

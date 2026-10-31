<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class SaveContactAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Contact
    {
        return DB::transaction(function () use ($data): Contact {
            $addressData = $data['address'] ?? null;
            unset($data['address']);

            $contact = Contact::create($data);

            if ($addressData !== null) {
                $contact->addresses()->create([
                    'type' => 'billing',
                    ...$addressData,
                ]);
            }

            return $contact->load('addresses');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Contact $contact, array $data): Contact
    {
        return DB::transaction(function () use ($contact, $data): Contact {
            $addressData = $data['address'] ?? null;
            unset($data['address']);

            $contact->update($data);

            if ($addressData !== null) {
                $billingAddress = $contact->addresses()->where('type', 'billing')->first();
                if ($billingAddress) {
                    $billingAddress->update($addressData);
                } else {
                    $contact->addresses()->create([
                        'type' => 'billing',
                        ...$addressData,
                    ]);
                }
            }

            return $contact->load('addresses');
        });
    }
}

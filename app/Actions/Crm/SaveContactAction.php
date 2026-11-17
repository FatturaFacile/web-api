<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Models\Contact;
use App\Services\Crm\ContactAddressService;
use Illuminate\Support\Facades\DB;

class SaveContactAction
{
    public function __construct(private readonly ContactAddressService $addresses) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Contact
    {
        return DB::transaction(function () use ($data): Contact {
            [$addressData, $addresses] = $this->extractAddresses($data);

            $contact = Contact::create($data);

            if ($addressData !== null) {
                $this->addresses->create($contact, ['type' => 'billing', ...$addressData]);
            }

            foreach ($addresses as $address) {
                $this->addresses->create($contact, $address);
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
            [$addressData, $addresses] = $this->extractAddresses($data);

            $contact->update($data);

            if ($addressData !== null) {
                $billingAddress = $contact->addresses()->where('type', 'billing')->where('is_default', true)->first()
                    ?? $contact->addresses()->where('type', 'billing')->first();

                if ($billingAddress) {
                    $billingAddress->update($addressData);
                } else {
                    $this->addresses->create($contact, ['type' => 'billing', ...$addressData]);
                }
            }

            foreach ($addresses as $address) {
                $this->addresses->create($contact, $address);
            }

            return $contact->load('addresses');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>|null, 1: array<int, array<string, mixed>>}
     */
    private function extractAddresses(array &$data): array
    {
        $legacy = $data['address'] ?? null;
        $list = $data['addresses'] ?? [];
        unset($data['address'], $data['addresses']);

        return [$legacy, $list];
    }
}

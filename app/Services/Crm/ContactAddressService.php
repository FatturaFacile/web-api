<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\ContactAddress;
use Illuminate\Support\Facades\DB;

/**
 * Unlimited addresses per customer with exactly one default per type (legal, billing, shipping, site).
 * The first address of a type is promoted to default automatically, and a deleted default is handed over to the
 * oldest remaining address of the same type.
 */
class ContactAddressService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Contact $contact, array $data): ContactAddress
    {
        return DB::transaction(function () use ($contact, $data): ContactAddress {
            $type = (string) ($data['type'] ?? 'billing');
            $hasDefault = $contact->addresses()->where('type', $type)->where('is_default', true)->exists();
            $wantsDefault = (bool) ($data['is_default'] ?? false);

            if ($wantsDefault) {
                $this->clearDefault($contact, $type);
            }

            return $contact->addresses()->create([
                ...$data,
                'type' => $type,
                'is_default' => $wantsDefault || ! $hasDefault,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ContactAddress $address, array $data): ContactAddress
    {
        return DB::transaction(function () use ($address, $data): ContactAddress {
            $contact = $address->contact;
            $oldType = $address->type;
            $newType = (string) ($data['type'] ?? $oldType);
            $wantsDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : $address->is_default;

            if ($newType !== $oldType) {
                // Moving to another type: it only keeps the default flag if the target type has none.
                $wantsDefault = $wantsDefault && ! $contact->addresses()->where('type', $newType)->where('is_default', true)->exists();
            } elseif ($wantsDefault && ! $address->is_default) {
                $this->clearDefault($contact, $newType);
            }

            $address->update([...$data, 'type' => $newType, 'is_default' => $wantsDefault]);

            if ($newType !== $oldType) {
                $this->ensureDefault($contact, $oldType);
                $this->ensureDefault($contact, $newType);
            } elseif (! $wantsDefault) {
                // Un-flagging the default of a type promotes another address, so a type never stays without one.
                $this->ensureDefault($contact, $newType);
            }

            return $address->refresh();
        });
    }

    public function setDefault(ContactAddress $address): ContactAddress
    {
        return DB::transaction(function () use ($address): ContactAddress {
            $this->clearDefault($address->contact, $address->type);
            $address->update(['is_default' => true]);

            return $address->refresh();
        });
    }

    public function delete(ContactAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $contact = $address->contact;
            $type = $address->type;
            $address->delete();
            $this->ensureDefault($contact, $type);
        });
    }

    private function clearDefault(Contact $contact, string $type): void
    {
        $contact->addresses()->where('type', $type)->where('is_default', true)->update(['is_default' => false]);
    }

    private function ensureDefault(Contact $contact, string $type): void
    {
        if ($contact->addresses()->where('type', $type)->where('is_default', true)->exists()) {
            return;
        }

        $next = $contact->addresses()->where('type', $type)->orderBy('created_at')->orderBy('id')->first();

        if ($next !== null) {
            $next->update(['is_default' => true]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\Invoice;
use Illuminate\Validation\ValidationException;
use Modules\SDI\Enums\SdiChannel;

/**
 * Fiscal checks that must pass before a file leaves the application on a real channel. The XML builder has placeholders
 * for missing data, which must never reach SDI.
 */
final class SdiPreflight
{
    public function __construct(private readonly SdiGatewayManager $gateways) {}

    /**
     * @throws ValidationException listing everything that has to be fixed
     */
    public function assertReady(Invoice $invoice): void
    {
        $tenant = $invoice->tenant;
        $channel = $this->gateways->channelFor($tenant);

        if ($channel === null || ! $channel->isReal()) {
            return;
        }

        $errors = [...$this->tenantErrors($invoice), ...$this->customerErrors($invoice), ...$this->lineErrors($invoice)];

        if ($channel === SdiChannel::Pec) {
            $errors = [...$errors, ...$this->pecErrors($invoice)];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['invoice' => $errors]);
        }
    }

    /**
     * @return list<string>
     */
    private function tenantErrors(Invoice $invoice): array
    {
        $tenant = $invoice->tenant;
        $errors = [];

        if (empty($tenant->vat_number)) {
            $errors[] = __('sdi.preflight.tenant_vat');
        }

        foreach (['street', 'postal_code', 'city'] as $field) {
            if (empty($tenant->settings[$field] ?? null)) {
                $errors[] = __('sdi.preflight.tenant_address', ['field' => $field]);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function customerErrors(Invoice $invoice): array
    {
        $contact = $invoice->contact;
        $errors = [];

        if (empty($contact->vat_number) && empty($contact->fiscal_code)) {
            $errors[] = __('sdi.preflight.customer_identity');
        }

        $address = $contact->addresses()->where('type', 'billing')->first();

        if ($address === null || empty($address->street) || empty($address->postal_code) || empty($address->city)) {
            $errors[] = __('sdi.preflight.customer_address');
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function lineErrors(Invoice $invoice): array
    {
        $hasZeroRate = $invoice->items->contains(fn ($item): bool => (float) $item->tax_rate === 0.0);

        if ($hasZeroRate && ! $invoice->reverse_charge && empty($invoice->tenant->settings['sdi_zero_rate_nature'] ?? null)) {
            return [__('sdi.preflight.zero_rate_nature')];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function pecErrors(Invoice $invoice): array
    {
        $tenant = $invoice->tenant;
        $contact = $invoice->contact;
        $errors = [];

        // Files for public administrations must carry a qualified signature, which this channel cannot apply.
        if ($contact->sdi_code !== null && strlen(trim($contact->sdi_code)) === 6) {
            $errors[] = __('sdi.preflight.pec_public_administration');
        }

        // SDI refuses its own mailboxes as a recipient (error 00330).
        if ($contact->pec !== null && str_ends_with(strtolower($contact->pec), '@pec.fatturapa.it')) {
            $errors[] = __('sdi.preflight.pec_recipient_is_sdi');
        }

        if ($tenant->sdi_pec_first_sent_at !== null && empty($tenant->sdi_pec_address)) {
            $errors[] = __('sdi.preflight.pec_waiting_address');
        }

        return $errors;
    }
}

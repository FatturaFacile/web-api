<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\Invoice;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use Illuminate\Validation\ValidationException;
use Modules\SDI\Enums\SdiChannel;
use Modules\SDI\Enums\TipoDocumento;

/**
 * Fiscal checks a file must pass before it is built: the XML builder has no placeholders, so master data is checked on
 * every channel. The checks that depend on how the file leaves the application apply to the real channels only.
 */
final class SdiPreflight
{
    public function __construct(
        private readonly SdiGatewayManager $gateways,
        private readonly FatturaPaBuilderService $builder,
    ) {}

    /**
     * @throws ValidationException listing everything that has to be fixed
     */
    public function assertReady(Invoice $invoice): void
    {
        if ($invoice->sdi_excluded) {
            throw ValidationException::withMessages(['invoice' => ['This is an internal document and cannot be sent to the SDI.']]);
        }

        $errors = $this->masterDataErrors($invoice);
        $channel = $this->gateways->channelFor($invoice->tenant);

        if ($channel === SdiChannel::Pec) {
            $errors = [...$errors, ...$this->pecErrors($invoice)];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['invoice' => $errors]);
        }
    }

    /**
     * @throws ValidationException listing the missing master data
     */
    public function assertMasterData(Invoice $invoice): void
    {
        $errors = $this->masterDataErrors($invoice);

        if ($errors !== []) {
            throw ValidationException::withMessages(['invoice' => $errors]);
        }
    }

    /**
     * @return list<string>
     */
    private function masterDataErrors(Invoice $invoice): array
    {
        $type = TipoDocumento::ofInvoice($invoice);

        return [...$this->tenantErrors($invoice), ...$this->counterpartyErrors($invoice, $type), ...$this->lineErrors($invoice)];
    }

    /**
     * @return list<string>
     */
    private function tenantErrors(Invoice $invoice): array
    {
        $tenant = $invoice->tenant;
        $address = $this->builder->tenantAddress($tenant);
        $errors = [];

        if (empty($tenant->vat_number)) {
            $errors[] = __('sdi.preflight.tenant_vat');
        }

        foreach (['street', 'postal_code', 'city'] as $field) {
            if (empty($address[$field])) {
                $errors[] = __('sdi.preflight.tenant_address', ['field' => $field]);
            }
        }

        return $errors;
    }

    /**
     * The other party of the file: the customer, or the supplier on a self-invoice it integrates. On a document the
     * tenant addresses to itself there is none.
     *
     * @return list<string>
     */
    private function counterpartyErrors(Invoice $invoice, TipoDocumento $type): array
    {
        if ($type->isSameParty()) {
            return [];
        }

        $contact = $invoice->contact;
        $errors = [];

        if ($type->swapsParties() && empty($contact->vat_number)) {
            $errors[] = __('sdi.preflight.supplier_identity');
        }

        if (! $type->swapsParties() && empty($contact->vat_number) && empty($contact->fiscal_code)) {
            $errors[] = __('sdi.preflight.customer_identity');
        }

        $address = $contact->addresses()->where('type', 'billing')->first();

        if ($address === null || empty($address->street) || empty($address->postal_code) || empty($address->city)) {
            $errors[] = __($type->swapsParties() ? 'sdi.preflight.supplier_address' : 'sdi.preflight.customer_address');
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function lineErrors(Invoice $invoice): array
    {
        $hasZeroRate = $invoice->items->contains(fn ($item): bool => (float) $item->tax_rate === 0.0);

        if ($hasZeroRate && $this->builder->lineNature($invoice, 0.0) === null) {
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
        if ($this->builder->transmissionFormat($invoice) === 'FPA12') {
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

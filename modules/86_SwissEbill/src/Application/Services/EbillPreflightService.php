<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Services;

use App\Contracts\SwissQrBill\SwissQrBillGeneratorInterface;
use App\Exceptions\QrBillGenerationException;
use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Invoice;
use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;
use Modules\SwissEbill\Domain\Support\BillerIdValidator;
use Modules\SwissEbill\Domain\Support\SwissUid;
use Modules\SwissEbill\Models\EbillSetting;

/**
 * Pre-flight check of an eBill transmission: lists every mandatory datum the invoice, the biller or the recipient still lacks,
 * so the client shows the complete list at once and the payload builder never starts on incomplete data.
 */
final class EbillPreflightService
{
    private const array CURRENCIES = ['CHF', 'EUR'];

    public function __construct(private readonly SwissQrBillGeneratorInterface $qrBills) {}

    /**
     * @return list<array{code: string, field: string}>
     */
    public function violations(Invoice $invoice, ?EbillSetting $setting): array
    {
        $invoice->loadMissing(['tenant', 'contact.addresses', 'items']);
        $violations = [];
        $add = static function (string $code, string $field) use (&$violations): void {
            $violations[] = ['code' => $code, 'field' => $field];
        };

        $this->checkSettings($setting, $add);
        $this->checkInvoice($invoice, $add);
        $this->checkBiller($invoice, $add);
        $this->checkRecipient($invoice->contact, $add);

        return $violations;
    }

    /**
     * @param  callable(string, string): void  $add
     */
    private function checkSettings(?EbillSetting $setting, callable $add): void
    {
        if ($setting === null || ! $setting->ebill_enabled) {
            $add('ebill_disabled', 'settings.ebill_enabled');

            return;
        }

        if ($setting->biller_id === null || $setting->biller_id === '') {
            $add('biller_id_missing', 'settings.biller_id');
        } elseif (! BillerIdValidator::isValid($setting->biller_id)) {
            $add('biller_id_invalid', 'settings.biller_id');
        }
    }

    /**
     * @param  callable(string, string): void  $add
     */
    private function checkInvoice(Invoice $invoice, callable $add): void
    {
        if ($invoice->getAttribute('status') !== 'posted' || $invoice->invoice_type === 'proforma') {
            $add('invoice_not_issued', 'invoice.status');
        }

        if (! in_array($invoice->currency, self::CURRENCIES, true)) {
            $add('currency_unsupported', 'invoice.currency');
        }

        if ($invoice->due_date === null) {
            $add('due_date_missing', 'invoice.due_date');
        }

        if ($invoice->items->isEmpty()) {
            $add('lines_missing', 'invoice.items');
        }

        $allowed = (array) config('swiss_ebill.vat_rates', []);

        if ($invoice->date->year < 2024) {
            $allowed = [...$allowed, ...(array) config('swiss_ebill.legacy_vat_rates', [])];
        }

        foreach ($invoice->items as $index => $item) {
            if (! $this->rateAllowed((float) $item->tax_rate, $allowed)) {
                $add('vat_rate_unsupported', 'invoice.items.'.$index.'.tax_rate');
            }
        }
    }

    /**
     * @param  callable(string, string): void  $add
     */
    private function checkBiller(Invoice $invoice, callable $add): void
    {
        $tenant = $invoice->tenant;

        if ($tenant === null || SwissUid::normalize((string) $tenant->uid_number) === null) {
            $add($tenant === null || trim((string) $tenant->uid_number) === '' ? 'biller_uid_missing' : 'biller_uid_invalid', 'biller.uid_number');
        }

        if ($tenant === null || trim((string) ($tenant->qr_iban ?: $tenant->iban)) === '') {
            $add('biller_iban_missing', 'biller.iban');

            return;
        }

        try {
            $this->qrBills->generate($invoice);
        } catch (QrBillGenerationException) {
            $add('qr_bill_invalid', 'biller.qr_bill');
        }
    }

    /**
     * @param  callable(string, string): void  $add
     */
    private function checkRecipient(?Contact $contact, callable $add): void
    {
        if ($contact === null) {
            $add('recipient_missing', 'recipient');

            return;
        }

        if (trim((string) $contact->ebill_recipient_id) === '') {
            $add('recipient_ebill_id_missing', 'recipient.ebill_recipient_id');
        }

        if (EbillParticipantStatus::tryFrom((string) $contact->ebill_status)?->canReceive() !== true) {
            $add('recipient_not_enrolled', 'recipient.ebill_status');
        }

        if ($contact->ebill_channel_type === EbillChannelType::B2bEnterprise->value && ! SwissUid::isValid($contact->uid_number)) {
            $add('recipient_uid_missing', 'recipient.uid_number');
        }

        if (self::displayName($contact) === '') {
            $add('recipient_name_missing', 'recipient.name');
        }

        $address = self::address($contact);

        if ($address === null || trim((string) $address->postal_code) === '' || trim((string) $address->city) === '') {
            $add('recipient_address_missing', 'recipient.address');
        }
    }

    public static function displayName(Contact $contact): string
    {
        $company = trim((string) $contact->company_name);

        return $company !== '' ? $company : trim(trim((string) $contact->first_name).' '.trim((string) $contact->last_name));
    }

    public static function address(Contact $contact): ?ContactAddress
    {
        $addresses = $contact->addresses;

        return $addresses->firstWhere('type', 'billing') ?? $addresses->firstWhere('is_default', true) ?? $addresses->first();
    }

    /**
     * @param  list<float|int>  $allowed
     */
    private function rateAllowed(float $rate, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            if (abs($candidate - $rate) < 0.0001) {
                return true;
            }
        }

        return false;
    }
}

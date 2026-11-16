<?php

declare(strict_types=1);

namespace Modules\Marketplace\Mappers;

use Modules\Marketplace\Exceptions\RecordMappingException;
use Modules\Marketplace\Support\MappedRecord;

/**
 * Maps the charges, payouts and customers of Stripe and the payments and settlements of Mollie into payment and payout
 * records (the input of treasury reconciliation). Amounts are decimal strings, dates ISO 8601 UTC.
 */
final class PaymentRecordMapper extends AbstractRecordMapper
{
    protected function handled(): array
    {
        return [
            'stripe' => ['charges', 'payouts', 'customers'],
            'mollie' => ['payments', 'settlements'],
        ];
    }

    public function map(string $connectorKey, string $resource, array $record): MappedRecord
    {
        $id = $this->requiredId($record);

        return match ($resource) {
            'charges' => $this->stripeCharge($id, $record),
            'payouts' => $this->stripePayout($id, $record),
            'customers' => $this->stripeCustomer($id, $record),
            'payments' => $this->molliePayment($id, $record),
            'settlements' => $this->mollieSettlement($id, $record),
            default => throw new RecordMappingException(__('marketplace_errors.sync.unknown_resource', ['resource' => $resource])),
        };
    }

    public function cursorOf(string $connectorKey, string $resource, array $record): ?string
    {
        $created = $record['created'] ?? null;

        return $connectorKey === 'stripe' && is_int($created) ? (string) $created : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function stripeCharge(string $id, array $record): MappedRecord
    {
        $currency = $this->currency($record['currency'] ?? null);

        return new MappedRecord(MappedRecord::Payment, $id, [
            'amount' => $this->minorUnits($record['amount'] ?? null, $currency, 'amount'),
            'currency' => $currency,
            'status' => $this->optionalString($record, 'status'),
            'paid' => (bool) ($record['paid'] ?? false),
            'refunded' => (bool) ($record['refunded'] ?? false),
            'description' => $this->optionalString($record, 'description'),
            'customer_external_id' => $this->optionalString($record, 'customer'),
            'occurred_at' => $this->isoDate($record['created'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function stripePayout(string $id, array $record): MappedRecord
    {
        $currency = $this->currency($record['currency'] ?? null);

        return new MappedRecord(MappedRecord::Payout, $id, [
            'amount' => $this->minorUnits($record['amount'] ?? null, $currency, 'amount'),
            'currency' => $currency,
            'status' => $this->optionalString($record, 'status'),
            'reference' => $this->optionalString($record, 'statement_descriptor'),
            'occurred_at' => $this->isoDate($record['arrival_date'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function stripeCustomer(string $id, array $record): MappedRecord
    {
        $email = $this->optionalString($record, 'email');

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RecordMappingException(__('marketplace_errors.mapping.missing_field', ['field' => 'email']));
        }

        return new MappedRecord(MappedRecord::Customer, $id, ['email' => strtolower($email), 'name' => $this->optionalString($record, 'name')]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function molliePayment(string $id, array $record): MappedRecord
    {
        return new MappedRecord(MappedRecord::Payment, $id, [
            'amount' => $this->decimal(data_get($record, 'amount.value'), 'amount'),
            'currency' => $this->currency(data_get($record, 'amount.currency')),
            'status' => $this->optionalString($record, 'status'),
            'paid' => $this->optionalString($record, 'status') === 'paid',
            'refunded' => false,
            'description' => $this->optionalString($record, 'description'),
            'customer_external_id' => $this->optionalString($record, 'customerId'),
            'occurred_at' => $this->isoDate($record['createdAt'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function mollieSettlement(string $id, array $record): MappedRecord
    {
        return new MappedRecord(MappedRecord::Payout, $id, [
            'amount' => $this->decimal(data_get($record, 'amount.value'), 'amount'),
            'currency' => $this->currency(data_get($record, 'amount.currency')),
            'status' => $this->optionalString($record, 'status'),
            'reference' => $this->optionalString($record, 'reference'),
            'occurred_at' => $this->isoDate($record['settledAt'] ?? $record['createdAt'] ?? null),
        ]);
    }
}

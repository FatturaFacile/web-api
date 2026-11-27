<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows\Pain002;

/**
 * A pain.002 customer payment status report (SIX `pain.002.001.03.ch.02` / `pain.002.001.10.ch.02`): the message it
 * answers (`OrgnlMsgId`), the status of the whole file and of its payment information blocks, and the status of the
 * single transactions.
 */
final readonly class Pain002Report
{
    public const string REJECTED = 'RJCT';

    public const string PARTIALLY_ACCEPTED = 'PART';

    /**
     * Statuses meaning the bank took the payment: settled, settling, accepted, accepted with change.
     *
     * @var list<string>
     */
    public const array EXECUTED = ['ACSC', 'ACSP', 'ACCP', 'ACWC'];

    /**
     * @param  array<string, array{status: string, reason_code: ?string, additional_info: ?string}>  $paymentInformation  by `OrgnlPmtInfId`
     * @param  list<Pain002Transaction>  $transactions
     */
    public function __construct(
        public string $messageId,
        public string $originalMessageId,
        public ?string $originalMessageType,
        public ?string $groupStatus,
        public ?string $groupReasonCode,
        public ?string $groupAdditionalInfo,
        public array $paymentInformation,
        public array $transactions,
    ) {}

    /**
     * The transaction of a payment line: by end-to-end identifier, then by instruction identifier.
     */
    public function transactionFor(?string $endToEndId, ?string $instructionId): ?Pain002Transaction
    {
        foreach ($this->transactions as $transaction) {
            if ($endToEndId !== null && $endToEndId !== '' && $transaction->endToEndId === $endToEndId) {
                return $transaction;
            }
        }

        foreach ($this->transactions as $transaction) {
            if ($instructionId !== null && $instructionId !== '' && $transaction->instructionId === $instructionId) {
                return $transaction;
            }
        }

        return null;
    }
}

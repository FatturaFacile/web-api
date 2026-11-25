<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows;

/**
 * A validated payment file ready to be handed to the bank.
 */
final readonly class GeneratedPaymentFlow
{
    public function __construct(
        public PaymentFlow $flow,
        public string $xml,
        public string $sha256,
        public string $messageId,
        public int $transactions,
        public string $controlSum,
        public string $currency,
        public string $filename,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withXml = true): array
    {
        return [
            'flow' => $this->flow->value,
            'pain_version' => $this->flow->version()->value,
            'message_id' => $this->messageId,
            'transactions' => $this->transactions,
            'control_sum' => $this->controlSum,
            'currency' => $this->currency,
            'filename' => $this->filename,
            'sha256' => $this->sha256,
            'xml' => $withXml ? $this->xml : null,
        ];
    }
}

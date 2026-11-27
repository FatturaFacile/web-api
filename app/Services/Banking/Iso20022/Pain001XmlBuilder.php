<?php

declare(strict_types=1);

namespace App\Services\Banking\Iso20022;

use App\Exceptions\Pain001Exception;
use App\Services\SwissQrBill\QrBillGeneratorService;
use DOMDocument;
use DOMElement;

/**
 * Builds ISO 20022 customer credit transfer initiation files (pain.001.001.03 and pain.001.001.09).
 *
 * Swiss payments follow the QR-bill rules: a QR-IBAN is paid with a 27 digit QR reference (structured reference of
 * the proprietary type `QRR`), a standard IBAN with an ISO 11649 creditor reference (`SCOR`) or unstructured text.
 * A payment that breaks the pairing is refused instead of being sent to the bank.
 */
class Pain001XmlBuilder
{
    public function __construct(private readonly QrBillGeneratorService $references) {}

    /**
     * @throws Pain001Exception
     */
    public function build(Pain001Message $message, Pain001Version $version): string
    {
        if ($message->payments === []) {
            throw new Pain001Exception('A payment file needs at least one payment.');
        }

        foreach ($message->payments as $payment) {
            $this->assertReferenceMatchesAccount($payment);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS($version->namespace(), 'Document');
        $dom->appendChild($root);
        $initiation = $this->child($dom, $root, 'CstmrCdtTrfInitn');
        $count = (string) count($message->payments);
        $sum = $message->controlSum();

        $header = $this->child($dom, $initiation, 'GrpHdr');
        $this->child($dom, $header, 'MsgId', $this->text($message->messageId, 35));
        $this->child($dom, $header, 'CreDtTm', $message->createdAt->format('Y-m-d\TH:i:s'));
        $this->child($dom, $header, 'NbOfTxs', $count);
        $this->child($dom, $header, 'CtrlSum', $sum);
        $this->child($dom, $this->child($dom, $header, 'InitgPty'), 'Nm', $this->text($message->initiatorName, 140));

        $information = $this->child($dom, $initiation, 'PmtInf');
        $this->child($dom, $information, 'PmtInfId', $this->text($message->paymentInfoId, 35));
        $this->child($dom, $information, 'PmtMtd', 'TRF');
        $this->child($dom, $information, 'NbOfTxs', $count);
        $this->child($dom, $information, 'CtrlSum', $sum);

        if ($message->categoryPurpose !== null) {
            $this->child($dom, $this->child($dom, $this->child($dom, $information, 'PmtTpInf'), 'CtgyPurp'), 'Cd', $message->categoryPurpose->value);
        }

        $execution = $this->child($dom, $information, 'ReqdExctnDt');

        if ($version->wrapsExecutionDate()) {
            $this->child($dom, $execution, 'Dt', $message->executionDate->toDateString());
        } else {
            $execution->appendChild($dom->createTextNode($message->executionDate->toDateString()));
        }

        $this->child($dom, $this->child($dom, $information, 'Dbtr'), 'Nm', $this->text($message->debtorName, 140));
        $this->child($dom, $this->child($dom, $this->child($dom, $information, 'DbtrAcct'), 'Id'), 'IBAN', $this->iban($message->debtorIban));
        $this->institution($dom, $this->child($dom, $information, 'DbtrAgt'), $version, $message->debtorBic, true);

        if ($message->chargeBearer !== null) {
            $this->child($dom, $information, 'ChrgBr', $message->chargeBearer->value);
        }

        foreach ($message->payments as $payment) {
            $this->transaction($dom, $information, $version, $payment);
        }

        return (string) $dom->saveXML();
    }

    private function transaction(DOMDocument $dom, DOMElement $information, Pain001Version $version, Pain001Payment $payment): void
    {
        $transaction = $this->child($dom, $information, 'CdtTrfTxInf');
        $paymentId = $this->child($dom, $transaction, 'PmtId');

        if ($payment->instructionId !== null && $payment->instructionId !== '') {
            $this->child($dom, $paymentId, 'InstrId', $this->text($payment->instructionId, 35));
        }

        $this->child($dom, $paymentId, 'EndToEndId', $this->text($payment->endToEndId, 35));
        $amount = $this->child($dom, $this->child($dom, $transaction, 'Amt'), 'InstdAmt', number_format($payment->amount, 2, '.', ''));
        $amount->setAttribute('Ccy', strtoupper($payment->currency));

        if ($payment->creditorBic !== null && $payment->creditorBic !== '') {
            $this->institution($dom, $this->child($dom, $transaction, 'CdtrAgt'), $version, $payment->creditorBic, false);
        }

        $this->child($dom, $this->child($dom, $transaction, 'Cdtr'), 'Nm', $this->text($payment->creditorName, 140));
        $this->child($dom, $this->child($dom, $this->child($dom, $transaction, 'CdtrAcct'), 'Id'), 'IBAN', $this->iban($payment->creditorIban));

        $reference = $this->normalizedReference($payment->reference);
        $remittance = $this->child($dom, $transaction, 'RmtInf');

        if ($reference !== null) {
            $creditorReference = $this->child($dom, $this->child($dom, $remittance, 'Strd'), 'CdtrRefInf');
            $kind = $this->child($dom, $this->child($dom, $creditorReference, 'Tp'), 'CdOrPrtry');

            if ($this->referenceType($reference) === 'QRR') {
                $this->child($dom, $kind, 'Prtry', 'QRR');
            } else {
                $this->child($dom, $kind, 'Cd', 'SCOR');
            }

            $this->child($dom, $creditorReference, 'Ref', $reference);
        } elseif ($payment->remittanceText !== null && trim($payment->remittanceText) !== '') {
            $this->child($dom, $remittance, 'Ustrd', $this->text($payment->remittanceText, 140));
        } else {
            $transaction->removeChild($remittance);
        }
    }

    private function institution(DOMDocument $dom, DOMElement $agent, Pain001Version $version, ?string $bic, bool $required): void
    {
        $institution = $this->child($dom, $agent, 'FinInstnId');

        if ($bic !== null && $bic !== '') {
            $this->child($dom, $institution, $version->bicElement(), strtoupper(str_replace(' ', '', $bic)));
        } elseif ($required) {
            $this->child($dom, $this->child($dom, $institution, 'Othr'), 'Id', 'NOTPROVIDED');
        }
    }

    /**
     * QRR needs a QR-IBAN and a valid mod 10 reference, SCOR a standard IBAN and a valid ISO 11649 reference, and a
     * QR-IBAN cannot be paid without a QR reference.
     */
    private function assertReferenceMatchesAccount(Pain001Payment $payment): void
    {
        $iban = $this->iban($payment->creditorIban);
        $reference = $this->normalizedReference($payment->reference);
        $isQrIban = $this->references->isValidQrIban($iban);
        $type = $reference === null ? 'NON' : $this->referenceType($reference);
        $problem = match (true) {
            $type === 'QRR' && ! $this->references->validateScorReference($reference ?? '') => 'is not a valid QR reference (27 digits, modulo 10)',
            $type === 'SCOR' && ! $this->references->validateIso11649($reference ?? '') => 'is not a valid ISO 11649 creditor reference',
            $type === 'QRR' && ! $isQrIban => 'is a QR reference but the account is not a QR-IBAN',
            $type === 'SCOR' && $isQrIban => 'is a creditor reference but the account is a QR-IBAN, which needs a QR reference',
            $type === 'NON' && $isQrIban => 'is missing: a QR-IBAN needs a QR reference',
            default => null,
        };

        if ($problem !== null) {
            throw new Pain001Exception("The payment reference for {$payment->creditorName} {$problem}.", [$problem]);
        }
    }

    private function referenceType(string $reference): string
    {
        return ctype_digit($reference) ? 'QRR' : 'SCOR';
    }

    private function normalizedReference(?string $reference): ?string
    {
        $clean = strtoupper((string) preg_replace('/\s+/', '', (string) $reference));

        return $clean === '' ? null : $clean;
    }

    private function iban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    /**
     * Free text of the file: control characters are dropped, whitespace is collapsed and the value cut to the field
     * length of the schema.
     */
    private function text(string $value, int $maxLength): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, $maxLength);
    }

    private function child(DOMDocument $dom, DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $dom->createElementNS($parent->namespaceURI, $name);

        if ($value !== null) {
            $element->appendChild($dom->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }
}

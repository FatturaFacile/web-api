<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Sepa;

use DOMDocument;
use DOMElement;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\Payroll\Calculation\Dec;

/**
 * ISO 20022 pain.001.001.03 credit transfer file for the net salaries of a month: one payment information block with the
 * SEPA service level and the SALA category purpose (salary payment), one transaction per employee for the exact net amount.
 */
final class SalaryPain001Generator
{
    public const string Namespace = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03';

    /**
     * @param  array{name: string, iban: string, bic?: string|null}  $debtor
     * @param  list<array{name: string, iban: string, amount: string, end_to_end_id: string, remittance: string}>  $payments
     */
    public function generate(string $messageId, string $executionDate, array $debtor, array $payments, ?\DateTimeInterface $createdAt = null): string
    {
        if ($payments === []) {
            throw new ItalianPayrollException('Nessuno stipendio da disporre.');
        }

        $this->assertIban($debtor['iban'], 'ordinante');
        $total = '0.00';

        foreach ($payments as $payment) {
            $this->assertIban($payment['iban'], $payment['name']);

            if (! Dec::isPositive($payment['amount'])) {
                throw new ItalianPayrollException("Importo netto non valido per {$payment['name']}.");
            }

            $total = Dec::add($total, Dec::round($payment['amount']));
        }

        $total = Dec::round($total);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->appendChild($document->createElementNS(self::Namespace, 'Document'));
        $initiation = $this->child($document, $root, 'CstmrCdtTrfInitn');

        $header = $this->child($document, $initiation, 'GrpHdr');
        $this->text($document, $header, 'MsgId', $messageId, 35);
        $this->text($document, $header, 'CreDtTm', ($createdAt ?? now())->format('Y-m-d\TH:i:s'));
        $this->text($document, $header, 'NbOfTxs', (string) count($payments));
        $this->text($document, $header, 'CtrlSum', $total);
        $this->text($document, $this->child($document, $header, 'InitgPty'), 'Nm', $debtor['name'], 70);

        $info = $this->child($document, $initiation, 'PmtInf');
        $this->text($document, $info, 'PmtInfId', $messageId, 35);
        $this->text($document, $info, 'PmtMtd', 'TRF');
        $this->text($document, $info, 'BtchBookg', 'true');
        $this->text($document, $info, 'NbOfTxs', (string) count($payments));
        $this->text($document, $info, 'CtrlSum', $total);
        $type = $this->child($document, $info, 'PmtTpInf');
        $this->text($document, $this->child($document, $type, 'SvcLvl'), 'Cd', 'SEPA');
        $this->text($document, $this->child($document, $type, 'CtgyPurp'), 'Cd', 'SALA');
        $this->text($document, $info, 'ReqdExctnDt', $executionDate);
        $this->text($document, $this->child($document, $info, 'Dbtr'), 'Nm', $debtor['name'], 70);
        $this->text($document, $this->child($document, $this->child($document, $info, 'DbtrAcct'), 'Id'), 'IBAN', $this->iban($debtor['iban']));
        $this->agent($document, $this->child($document, $info, 'DbtrAgt'), $debtor['bic'] ?? null);
        $this->text($document, $info, 'ChrgBr', 'SLEV');

        foreach ($payments as $payment) {
            $transaction = $this->child($document, $info, 'CdtTrfTxInf');
            $this->text($document, $this->child($document, $transaction, 'PmtId'), 'EndToEndId', $payment['end_to_end_id'], 35);
            $this->child($document, $this->child($document, $transaction, 'Amt'), 'InstdAmt', ['Ccy' => 'EUR'])
                ->appendChild($document->createTextNode(Dec::round($payment['amount'])));
            $this->text($document, $this->child($document, $transaction, 'Cdtr'), 'Nm', $payment['name'], 70);
            $this->text($document, $this->child($document, $this->child($document, $transaction, 'CdtrAcct'), 'Id'), 'IBAN', $this->iban($payment['iban']));
            $this->text($document, $this->child($document, $transaction, 'RmtInf'), 'Ustrd', $payment['remittance'], 140);
        }

        return (string) $document->saveXML();
    }

    public function isValidIban(string $iban): bool
    {
        $iban = $this->iban($iban);

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    private function assertIban(string $iban, string $holder): void
    {
        if (! $this->isValidIban($iban)) {
            throw new ItalianPayrollException("IBAN non valido per {$holder}.");
        }
    }

    private function iban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    private function agent(DOMDocument $document, DOMElement $agent, ?string $bic): void
    {
        $institution = $this->child($document, $agent, 'FinInstnId');

        if ($bic !== null && $bic !== '') {
            $this->text($document, $institution, 'BIC', strtoupper($bic));

            return;
        }

        $this->text($document, $this->child($document, $institution, 'Othr'), 'Id', 'NOTPROVIDED');
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function child(DOMDocument $document, DOMElement|\DOMNode $parent, string $name, array $attributes = []): DOMElement
    {
        $element = $document->createElementNS(self::Namespace, $name);

        foreach ($attributes as $key => $value) {
            $element->setAttribute($key, $value);
        }

        $parent->appendChild($element);

        return $element;
    }

    private function text(DOMDocument $document, DOMElement $parent, string $name, string $value, int $max = 0): void
    {
        $clean = (string) preg_replace('/[^\p{L}\p{N} \/\-?:().,\'+]/u', ' ', $value);
        $clean = $max > 0 ? mb_substr(trim($clean), 0, $max) : trim($clean);

        $this->child($document, $parent, $name)->appendChild($document->createTextNode($clean));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Treasury\Models\RibaPresentation;
use Modules\Treasury\Models\RibaReceipt;

/**
 * Writes the Ri.Ba. CBI flat file: IB header, seven 120-character records per receipt (14, 20, 30, 40, 50, 51, 70) and
 * EF trailer, with CRLF line ends.
 *
 * The positions come from the public ING addendum to CBI-RIB-001 v6.02 (2016), cross-checked with its sample file and
 * with the open source devcode-it/cbi library. The CBI document itself could not be read, so a bank must accept a test
 * file before production use.
 */
final class RibaFlatFileBuilder
{
    private const string ReasonCode = '30000';

    /**
     * @param  array{creditor_name: string, creditor_tax_code: string, abi: string, cab: string, account: string}  $creditor
     */
    public function build(RibaPresentation $presentation, array $creditor, string $supportName, CarbonInterface $createdAt): string
    {
        $receipts = $presentation->receipts()->orderBy('receipt_number')->get();
        $sia = $presentation->sia_code;
        $date = $createdAt->format('dmy');
        $total = '0.00';

        $records = [$this->record([
            [1, 1, ' '], [2, 3, 'IB'], [4, 8, $sia], [9, 13, $creditor['abi']], [14, 19, $date], [20, 39, $supportName],
            [105, 105, '1'], [106, 106, '$'], [114, 114, 'E'], [116, 120, '00000'],
        ])];

        foreach ($receipts as $sequence => $receipt) {
            $total = bcadd($total, (string) $receipt->amount, 2);
            array_push($records, ...$this->receiptRecords($receipt, $sequence + 1, $sia, $creditor));
        }

        $records[] = $this->record([
            [1, 1, ' '], [2, 3, 'EF'], [4, 8, $sia], [9, 13, $creditor['abi']], [14, 19, $date], [20, 39, $supportName],
            [46, 52, $this->digits((string) $receipts->count(), 7)],
            [53, 67, $this->digits($this->cents($total), 15)],
            [68, 82, $this->digits('0', 15)],
            [83, 89, $this->digits((string) (count($records) + 1), 7)],
            [114, 114, 'E'],
        ]);

        return implode("\r\n", $records)."\r\n";
    }

    /**
     * @param  array{creditor_name: string, creditor_tax_code: string, abi: string, cab: string, account: string}  $creditor
     * @return list<string>
     */
    private function receiptRecords(RibaReceipt $receipt, int $sequence, string $sia, array $creditor): array
    {
        $number = $this->digits((string) $sequence, 7);
        $head = fn (string $type): array => [[1, 1, ' '], [2, 3, $type], [4, 10, $number]];
        $reference = 'FATT. '.(string) $receipt->invoice?->invoice_number.' DEL '.(string) $receipt->invoice?->date?->format('d/m/Y');

        return [
            $this->record([
                ...$head('14'),
                [23, 28, $receipt->due_date->format('dmy')],
                [29, 33, self::ReasonCode],
                [34, 46, $this->digits($this->cents((string) $receipt->amount), 13)],
                [47, 47, '-'],
                [48, 52, $creditor['abi']],
                [53, 57, $creditor['cab']],
                [58, 69, str_pad($creditor['account'], 12, '0', STR_PAD_LEFT)],
                [70, 74, $receipt->debtor_abi],
                [75, 79, $receipt->debtor_cab],
                [92, 96, $sia],
                [97, 97, '4'],
                [120, 120, 'E'],
            ]),
            $this->record([...$head('20'), [11, 34, $creditor['creditor_name']]]),
            $this->record([...$head('30'), [11, 40, $receipt->debtor_name], [71, 86, $receipt->debtor_tax_code]]),
            $this->record([
                ...$head('40'),
                [11, 40, $receipt->debtor_address],
                [41, 45, $receipt->debtor_postal_code],
                [46, 70, $receipt->debtor_city],
            ]),
            $this->record([...$head('50'), [11, 50, $reference], [101, 116, $creditor['creditor_tax_code']]]),
            $this->record([...$head('51'), [11, 20, $this->digits((string) $receipt->receipt_number, 10)], [21, 40, $creditor['creditor_name']]]),
            $this->record($head('70')),
        ];
    }

    /**
     * @param  list<array{0: int, 1: int, 2: string}>  $fields  first position, last position and value
     */
    private function record(array $fields): string
    {
        $line = str_repeat(' ', 120);

        foreach ($fields as [$from, $to, $value]) {
            $length = $to - $from + 1;
            $line = substr_replace($line, str_pad(substr($this->clean($value), 0, $length), $length), $from - 1, $length);
        }

        return $line;
    }

    /**
     * Keeps the characters of the published character set; accents become plain letters.
     */
    private function clean(string $value): string
    {
        return (string) preg_replace("/[^a-zA-Z0-9\\/\\- :().,'+\$]/", ' ', Str::ascii($value));
    }

    private function cents(string $amount): string
    {
        return bcmul($amount, '100', 0);
    }

    private function digits(string $value, int $length): string
    {
        if (strlen($value) > $length) {
            throw new InvalidArgumentException("Value {$value} does not fit {$length} digits of a Ri.Ba. record.");
        }

        return str_pad($value, $length, '0', STR_PAD_LEFT);
    }
}

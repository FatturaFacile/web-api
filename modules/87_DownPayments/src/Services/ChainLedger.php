<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use Modules\DownPayments\Domain\Enums\ChainStatus;
use Modules\DownPayments\Domain\Enums\DepositStatus;
use Modules\DownPayments\Domain\Enums\DepositType;
use Modules\DownPayments\Domain\Enums\LinkStatus;
use Modules\DownPayments\Domain\Enums\LinkType;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The analytical ledger of a chain per VAT rate: what the source holds, what the advance invoices and pro-forma requests took
 * of it, what credit notes gave back, what the final invoice deducted and what is still open. Also keeps the totals and the
 * status of the chain in line with its links.
 *
 * @phpstan-type MatrixRow array{vat_rate: string, contract_net: string, contract_vat: string, contract_gross: string, requested_net: string, requested_vat: string, requested_gross: string, invoiced_net: string, invoiced_vat: string, invoiced_gross: string, credited_net: string, credited_vat: string, credited_gross: string, settled_net: string, settled_vat: string, settled_gross: string, deductible_net: string, deductible_vat: string, deductible_gross: string, residual_net: string, residual_vat: string, residual_gross: string}
 */
final class ChainLedger
{
    /**
     * The residual of the order per VAT rate: the net the source holds less what advances and open requests still take of it
     * (credit notes give theirs back), with the VAT and the gross that net bears.
     *
     * @return array<string, array{net: string, vat: string, gross: string}>
     */
    public function residualByRate(DownPaymentChain $chain): array
    {
        $residual = [];

        foreach ($this->matrix($chain) as $row) {
            $residual[$row['vat_rate']] = ['net' => $row['residual_net'], 'vat' => $row['residual_vat'], 'gross' => $row['residual_gross']];
        }

        return $residual;
    }

    /**
     * @return list<MatrixRow>
     */
    public function matrix(DownPaymentChain $chain): array
    {
        $rows = [];

        foreach (($chain->vat_bases ?? []) as $rate => $net) {
            $rate = Decimal::round2((string) $rate);
            $rows[$rate] = $this->emptyRow($rate, (string) $net);
        }

        $links = $chain->links()->with('vatLines')->get();

        foreach ($links as $link) {
            /** @var DownPaymentLink $link */
            $type = LinkType::from($link->link_type);
            $status = LinkStatus::from($link->status);

            if (in_array($status, [LinkStatus::Cancelled, LinkStatus::Converted], true) || ! in_array($type, [LinkType::AdvanceInvoiceTd02, LinkType::ProformaAdvanceRequest], true)) {
                continue;
            }

            foreach ($link->vatLines as $line) {
                $rate = Decimal::round2((string) $line->vat_percentage);
                $rows[$rate] ??= $this->emptyRow($rate, '0');
                $row = &$rows[$rate];

                if ($type === LinkType::ProformaAdvanceRequest) {
                    foreach (['net', 'vat', 'gross'] as $part) {
                        $row['requested_'.$part] = Decimal::add($row['requested_'.$part], (string) $line->{$part.'_amount'});
                    }
                } else {
                    foreach (['net', 'vat', 'gross'] as $part) {
                        $row['invoiced_'.$part] = Decimal::add($row['invoiced_'.$part], (string) $line->{$part.'_amount'});
                        $row['credited_'.$part] = Decimal::add($row['credited_'.$part], (string) $line->{'credited_'.$part.'_amount'});
                        $row['settled_'.$part] = Decimal::add($row['settled_'.$part], (string) $line->{'settled_'.$part.'_amount'});
                        $row['deductible_'.$part] = Decimal::add($row['deductible_'.$part], (string) $line->{'residual_'.$part.'_amount'});
                    }
                }

                unset($row);
            }
        }

        ksort($rows, SORT_NUMERIC);

        foreach ($rows as $rate => $row) {
            $taken = Decimal::add(Decimal::sub($row['invoiced_net'], $row['credited_net']), $row['requested_net']);
            $residualNet = Decimal::round2(Decimal::sub($row['contract_net'], $taken));
            $residualNet = Decimal::isNegative($residualNet) ? '0.00' : $residualNet;
            $vat = Decimal::round2(Decimal::div(Decimal::mul($residualNet, (string) $rate), '100'));

            $rows[$rate] = $this->rounded([...$row, 'residual_net' => $residualNet, 'residual_vat' => $vat, 'residual_gross' => Decimal::add($residualNet, $vat)]);
        }

        return array_values($rows);
    }

    /**
     * Re-derives the totals and the status of the chain from its links and deposits.
     */
    public function refresh(DownPaymentChain $chain): DownPaymentChain
    {
        $bases = $chain->vat_bases ?? [];
        $net = '0';
        $vat = '0';

        foreach ($bases as $rate => $base) {
            $net = Decimal::add($net, (string) $base);
            $vat = Decimal::add($vat, Decimal::round2(Decimal::div(Decimal::mul((string) $base, (string) $rate), '100')));
        }

        $matrix = $this->matrix($chain);
        $sum = static fn (string $key): string => Decimal::round2(array_reduce($matrix, static fn (string $carry, array $row): string => Decimal::add($carry, $row[$key]), '0'));
        $held = static fn (DepositType $type): string => Decimal::round2((string) $chain->deposits()->where('deposit_type', $type->value)->where('status', DepositStatus::HeldActive->value)->sum('amount'));

        $chain->total_contract_net = Decimal::round2($net);
        $chain->total_contract_vat = Decimal::round2($vat);
        $chain->total_contract_gross = Decimal::round2(Decimal::add($net, $vat));
        $chain->total_advance_invoiced_net = $sum('invoiced_net');
        $chain->total_advance_invoiced_vat = $sum('invoiced_vat');
        $chain->total_advance_invoiced_gross = $sum('invoiced_gross');
        $chain->total_credited_advances_gross = $sum('credited_gross');
        $chain->total_earnest_deposits_held = $held(DepositType::EarnestMoney);
        $chain->total_security_deposits_held = $held(DepositType::SecurityDeposit);
        $chain->residual_order_gross = $sum('residual_gross');
        $chain->status = $this->statusOf($chain)->value;
        $chain->save();

        return $chain;
    }

    private function statusOf(DownPaymentChain $chain): ChainStatus
    {
        $current = ChainStatus::from($chain->status);

        if ($current === ChainStatus::Cancelled) {
            return $current;
        }

        if ($chain->final_invoice_id !== null) {
            return ChainStatus::Settled;
        }

        $links = $chain->links()->get();
        $settledAny = $links->contains(static fn (DownPaymentLink $link): bool => $link->status === LinkStatus::Settled->value);
        $forfeited = $chain->deposits()->where('status', DepositStatus::ForfeitedOnBreach->value)->exists();
        $anyDocument = $links->isNotEmpty() || $chain->deposits()->exists();

        return match (true) {
            $forfeited => ChainStatus::Forfeited,
            $settledAny => ChainStatus::PartiallySettled,
            $anyDocument || $chain->installments()->exists() => ChainStatus::Active,
            default => ChainStatus::Draft,
        };
    }

    /**
     * @return MatrixRow
     */
    private function emptyRow(string $rate, string $net): array
    {
        $net = Decimal::round2($net);
        $vat = Decimal::round2(Decimal::div(Decimal::mul($net, $rate), '100'));
        $zero = '0.00';

        return [
            'vat_rate' => $rate, 'contract_net' => $net, 'contract_vat' => $vat, 'contract_gross' => Decimal::round2(Decimal::add($net, $vat)),
            'requested_net' => $zero, 'requested_vat' => $zero, 'requested_gross' => $zero,
            'invoiced_net' => $zero, 'invoiced_vat' => $zero, 'invoiced_gross' => $zero,
            'credited_net' => $zero, 'credited_vat' => $zero, 'credited_gross' => $zero,
            'settled_net' => $zero, 'settled_vat' => $zero, 'settled_gross' => $zero,
            'deductible_net' => $zero, 'deductible_vat' => $zero, 'deductible_gross' => $zero,
            'residual_net' => $zero, 'residual_vat' => $zero, 'residual_gross' => $zero,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return MatrixRow
     */
    private function rounded(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($key !== 'vat_rate') {
                $row[$key] = Decimal::round2($value);
            }
        }

        /** @var MatrixRow $row */
        return $row;
    }
}

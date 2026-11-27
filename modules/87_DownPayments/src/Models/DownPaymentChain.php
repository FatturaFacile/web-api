<?php

declare(strict_types=1);

namespace Modules\DownPayments\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A down-payment chain: a source document (sales order, quote or contract) with its payment plan, the advance invoices,
 * deposits and credit notes issued against it and, at the end, the final settlement invoice that deducts them.
 *
 * @property string $chain_number
 * @property string $source_type
 * @property string $source_id
 * @property string|null $source_number
 * @property string|null $sales_order_id
 * @property string $customer_id
 * @property string|null $customer_name
 * @property string $currency
 * @property string $jurisdiction
 * @property string $total_contract_net
 * @property string $total_contract_vat
 * @property string $total_contract_gross
 * @property string $total_earnest_deposits_held
 * @property string $total_security_deposits_held
 * @property string $total_advance_invoiced_net
 * @property string $total_advance_invoiced_vat
 * @property string $total_advance_invoiced_gross
 * @property string $total_credited_advances_gross
 * @property string $residual_order_gross
 * @property string $status
 * @property array<string, string>|null $vat_bases
 * @property list<array{description: string, vat_rate: string, net: string}>|null $supply_lines
 * @property string|null $final_invoice_id
 * @property string|null $notes
 */
final class DownPaymentChain extends BaseEnterpriseModel
{
    protected $table = 'down_payment_chains';

    protected function casts(): array
    {
        return [
            'total_contract_net' => 'decimal:2',
            'total_contract_vat' => 'decimal:2',
            'total_contract_gross' => 'decimal:2',
            'total_earnest_deposits_held' => 'decimal:2',
            'total_security_deposits_held' => 'decimal:2',
            'total_advance_invoiced_net' => 'decimal:2',
            'total_advance_invoiced_vat' => 'decimal:2',
            'total_advance_invoiced_gross' => 'decimal:2',
            'total_credited_advances_gross' => 'decimal:2',
            'residual_order_gross' => 'decimal:2',
            'vat_bases' => 'array',
            'supply_lines' => 'array',
        ];
    }

    /**
     * @return HasMany<DownPaymentInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(DownPaymentInstallment::class, 'chain_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<DownPaymentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(DownPaymentLink::class, 'chain_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<EarnestAndSecurityDeposit, $this>
     */
    public function deposits(): HasMany
    {
        return $this->hasMany(EarnestAndSecurityDeposit::class, 'chain_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<FinalSettlementDeductionLine, $this>
     */
    public function deductionLines(): HasMany
    {
        return $this->hasMany(FinalSettlementDeductionLine::class, 'chain_id')->orderBy('created_at')->orderBy('id');
    }
}

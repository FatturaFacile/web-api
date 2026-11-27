<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Handlers;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\GreenCare\Application\Commands\GenerateGreenCareBillingCommand;
use Modules\GreenCare\Application\Services\GreenCareBillingBuilder;
use Modules\GreenCare\Domain\Enums\BillingStatus;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\GreenWasteSlip;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;
use Modules\Invoicing\Services\InvoicingService;

/**
 * Generates the draft invoice of a seasonal contract in the invoicing module, mixing the fixed installment and the winter standby fee with
 * the extra passages, the time and material hours, the plants and the billable green waste. The invoice of an Italian condominium carries the
 * 4% withholding (ritenuta d'acconto, Art. 25-ter DPR 600/73) and the FatturaPA metadata: fiscal code of the condominium and the reference to
 * the administrator. What is invoiced is marked as such, so a second run bills only what is new.
 */
final class GenerateGreenCareBillingHandler
{
    public function __construct(
        private readonly GreenCareBillingBuilder $builder,
        private readonly InvoicingService $invoicing,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @return array<string, mixed> the preview plus `invoice_id` and `invoice_status` (null on a dry run)
     */
    public function handle(GenerateGreenCareBillingCommand $command): array
    {
        return DB::transaction(function () use ($command): array {
            /** @var SeasonalMaintenanceContract $contract */
            $contract = SeasonalMaintenanceContract::query()->with(['site', 'customer', 'condominiumAdministrator'])->whereKey($command->contractId)->lockForUpdate()->firstOrFail();
            $preview = $this->builder->build($contract, $command);

            if ($command->dryRun) {
                return $this->present($preview, null, null);
            }

            $tenantId = (string) $this->tenants->getTenantId();
            $invoice = $this->invoicing->createInvoice($this->invoiceData($contract, $preview, $tenantId, $command), array_map(
                static fn (array $line): array => array_filter([
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['tax_rate'],
                    'product_id' => $line['product_id'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
                $preview['lines'],
            ));

            $this->attachFatturaPaMetadata($invoice, $contract, $preview);
            $this->markBilled($contract, $preview, (string) $invoice->id);

            return $this->present($preview, (string) $invoice->id, (string) $invoice->status);
        });
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function invoiceData(SeasonalMaintenanceContract $contract, array $preview, string $tenantId, GenerateGreenCareBillingCommand $command): array
    {
        $tenant = Tenant::query()->find($tenantId);
        $data = [
            'tenant_id' => $tenantId,
            'contact_id' => $contract->customer_id,
            'jurisdiction' => $contract->jurisdiction,
            'currency' => (string) ($tenant->default_currency ?? ($contract->jurisdiction === 'CH' ? 'CHF' : 'EUR')),
            'notes' => (string) __('green_care.billing.invoice_note', ['contract' => $contract->contract_number, 'site' => $contract->site->name ?? '']),
            'created_by' => $command->userId,
        ];

        if ($preview['withholding'] !== null) {
            $data += [
                'withholding_tax_enabled' => true,
                'withholding_tax_percent' => $preview['withholding']['percent'],
                'withholding_tax_type' => $preview['withholding']['type'],
                'withholding_tax_reason' => $preview['withholding']['reason'],
                'withholding_tax_base_percent' => 100,
            ];
        }

        return $data;
    }

    /**
     * Codice Fiscale of the condominium, the administrator and the contract in the FatturaPA tree (`sdi_extra`), with the legal basis of the withholding.
     *
     * @param  array<string, mixed>  $preview
     */
    private function attachFatturaPaMetadata(\Modules\Invoicing\Models\SalesInvoice $invoice, SeasonalMaintenanceContract $contract, array $preview): void
    {
        if (! $contract->is_condominium) {
            return;
        }

        $causale = [sprintf('Condominio C.F. %s', $contract->condominium_fiscal_code)];
        $administrator = $preview['condominium']['administrator_name'] ?? null;

        if ($administrator !== null) {
            $causale[] = 'Amministratore di condominio: '.$administrator;
        }

        if ($preview['withholding'] !== null) {
            $causale[] = sprintf('Ritenuta %s%% ex %s', rtrim(rtrim(number_format((float) $preview['withholding']['percent'], 2, '.', ''), '0'), '.'), $preview['withholding']['legal_basis']);
        }

        $invoice->sdi_extra = json_encode([
            'FatturaElettronicaHeader' => ['CessionarioCommittente' => ['DatiAnagrafici' => ['CodiceFiscale' => $contract->condominium_fiscal_code]]],
            'FatturaElettronicaBody' => ['DatiGenerali' => [
                'DatiGeneraliDocumento' => ['Causale' => array_map(static fn (string $text): string => mb_substr($text, 0, 200), $causale)],
                'DatiContratto' => [['IdDocumento' => mb_substr($contract->contract_number, 0, 20), 'Data' => $contract->season_start_date->format('Y-m-d')]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $invoice->save();
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function markBilled(SeasonalMaintenanceContract $contract, array $preview, string $invoiceId): void
    {
        /** @var \Illuminate\Support\Collection<int, CrewRouteStop> $stops */
        $stops = $preview['stops'];
        /** @var \Illuminate\Support\Collection<int, GreenWasteSlip> $slips */
        $slips = $preview['slips'];

        CrewRouteStop::query()->whereKey($stops->pluck('id')->all())->update(['billing_status' => BillingStatus::Invoiced->value, 'invoice_id' => $invoiceId]);
        GreenWasteSlip::query()->whereKey($slips->pluck('id')->all())->update(['invoice_id' => $invoiceId]);

        if ($preview['installment_number'] !== null) {
            $contract->installments_billed = $preview['installment_number'];
        }

        if ($preview['snow_standby']) {
            $contract->snow_standby_billed = true;
        }

        $contract->save();
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function present(array $preview, ?string $invoiceId, ?string $status): array
    {
        return [
            'lines' => $preview['lines'],
            'total_net' => $preview['total_net'],
            'total_vat' => $preview['total_vat'],
            'withholding' => $preview['withholding'],
            'net_payable' => $preview['net_payable'],
            'jurisdiction' => $preview['jurisdiction'],
            'condominium' => $preview['condominium'],
            'invoice_id' => $invoiceId,
            'invoice_status' => $status,
        ];
    }
}

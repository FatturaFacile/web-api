<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\Enums\CustomerHierarchyRole;
use Modules\SalesBoundedContext\Domain\Enums\InsolvencyRiskStatus;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Domain\ValueObjects\FiscalIdentity;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;

final readonly class UpsertCustomer360DossierHandler
{
    public function handle(UpsertCustomer360DossierCommand $command): SbcCustomerDossier
    {
        $data = $command->payload;
        $fiscal = FiscalIdentity::fromArray($data);

        $creditLimit = DecimalMoney::of((float) ($data['credit_limit'] ?? 0), (string) ($data['currency'] ?? 'CHF'));
        $exposure = DecimalMoney::of((float) ($data['current_exposure'] ?? 0), $creditLimit->currency);
        $overdue = DecimalMoney::of((float) ($data['overdue_balance'] ?? 0), $creditLimit->currency);

        $insolvency = isset($data['insolvency_status'])
            ? InsolvencyRiskStatus::from((string) $data['insolvency_status'])
            : ($overdue->toFloat() > 0 && $exposure->toFloat() > $creditLimit->toFloat()
                ? InsolvencyRiskStatus::CreditHold
                : ($overdue->toFloat() > 0 ? InsolvencyRiskStatus::Watchlist : InsolvencyRiskStatus::Performing));

        $riskScore = (int) ($data['risk_score'] ?? match ($insolvency) {
            InsolvencyRiskStatus::InsolventLegal => 95,
            InsolvencyRiskStatus::CreditHold => 75,
            InsolvencyRiskStatus::Watchlist => 45,
            InsolvencyRiskStatus::Performing => 15,
        });

        return DB::transaction(function () use ($command, $data, $fiscal, $creditLimit, $exposure, $overdue, $insolvency, $riskScore): SbcCustomerDossier {
            $dossier = $command->dossierId !== null
                ? SbcCustomerDossier::query()->where('tenant_id', $command->tenantId)->findOrFail($command->dossierId)
                : new SbcCustomerDossier(['tenant_id' => $command->tenantId]);

            $dossier->fill([
                'tenant_id' => $command->tenantId,
                'parent_dossier_id' => $data['parent_dossier_id'] ?? $dossier->parent_dossier_id,
                'contact_id' => $data['contact_id'] ?? $dossier->contact_id,
                'customer_code' => (string) ($data['customer_code'] ?? $dossier->customer_code ?? 'CUST-'.strtoupper(substr(md5((string) now()->timestamp), 0, 6))),
                'company_name' => (string) ($data['company_name'] ?? $dossier->company_name),
                'hierarchy_role' => CustomerHierarchyRole::from((string) ($data['hierarchy_role'] ?? $dossier->hierarchy_role?->value ?? 'subsidiary')),
                'country' => $fiscal->country,
                'currency' => (string) ($data['currency'] ?? ($fiscal->country === 'IT' ? 'EUR' : 'CHF')),
                'uid_number' => $fiscal->uidNumber,
                'vat_number' => $fiscal->vatNumber,
                'fiscal_code' => $fiscal->fiscalCode,
                'sdi_code' => $fiscal->sdiCode,
                'pec_email' => $fiscal->pecEmail,
                'qr_iban' => $fiscal->qrIban,
                'addresses' => $data['addresses'] ?? $dossier->addresses ?? [],
                'contacts' => $data['contacts'] ?? $dossier->contacts ?? [],
                'credit_limit' => $creditLimit->roundHalfUp(),
                'current_exposure' => $exposure->roundHalfUp(),
                'overdue_balance' => $overdue->roundHalfUp(),
                'insolvency_status' => $insolvency,
                'risk_score' => $riskScore,
                'summary_360' => [
                    'available_credit' => max(0.0, $creditLimit->toFloat() - $exposure->toFloat()),
                    'utilization_pct' => $creditLimit->toFloat() > 0 ? round(($exposure->toFloat() / $creditLimit->toFloat()) * 100.0, 2) : 0.0,
                    'fiscal_verified' => ($fiscal->uidNumber !== null || $fiscal->vatNumber !== null),
                ],
            ]);
            $dossier->save();

            return $dossier;
        });
    }
}

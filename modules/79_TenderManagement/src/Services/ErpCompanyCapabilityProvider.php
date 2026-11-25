<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Modules\Projects\Models\Project;
use Modules\TenderManagement\Contracts\CompanyCapabilityProviderInterface;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Domain\ValueObjects\CompanyProfile;
use Modules\TenderManagement\Models\TenderNotice;

/**
 * The company track record from the ERP: the net revenue invoiced over the reference years (Mod 005 sales invoices) and the
 * projects it delivered in that period (Mod 065), counted as similar when their contract value reaches the amount the
 * tender asks for.
 */
final class ErpCompanyCapabilityProvider implements CompanyCapabilityProviderInterface
{
    public function profileFor(TenderNotice $notice, CarbonImmutable $today): CompanyProfile
    {
        $since = $today->subYears(max(1, $notice->turnover_reference_years))->toDateString();

        $turnover = (string) Invoice::query()
            ->where('date', '>=', $since)
            ->where('date', '<=', $today->toDateString())
            ->where('invoice_type', '!=', 'proforma')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->sum('total_net');

        $minimum = $notice->required_similar_projects_amount ?? '0';
        $similar = Project::query()
            ->where('status', 'closed')
            ->where(fn ($query) => $query->where('actual_end_date', '>=', $since)->orWhere('closed_at', '>=', $since))
            ->where('contract_value', '>=', Dec::of($minimum))
            ->orderByDesc('contract_value')
            ->get(['id', 'contract_value']);

        return new CompanyProfile(
            Dec::money($turnover),
            $similar->count(),
            Dec::money($similar->first()->contract_value ?? '0'),
        );
    }
}

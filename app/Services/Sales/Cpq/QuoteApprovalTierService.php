<?php

declare(strict_types=1);

namespace App\Services\Sales\Cpq;

use App\Models\QuoteApprovalTier;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * The approval ladder of the active company.
 */
final readonly class QuoteApprovalTierService
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @return Collection<int, QuoteApprovalTier>
     */
    public function all(): Collection
    {
        return QuoteApprovalTier::query()->orderBy('level')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): QuoteApprovalTier
    {
        $tier = new QuoteApprovalTier($data);
        $tier->tenant_id = $this->tenantContext->getTenantId();
        $tier->save();

        return $tier;
    }

    public function find(string $id): QuoteApprovalTier
    {
        return QuoteApprovalTier::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QuoteApprovalTier $tier, array $data): QuoteApprovalTier
    {
        $tier->fill($data)->save();

        return $tier;
    }

    public function delete(QuoteApprovalTier $tier): void
    {
        $tier->delete();
    }
}

<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\SizeCluster;
use Modules\FranchiseNetwork\Domain\Enums\StoreStatus;
use Modules\FranchiseNetwork\Domain\Enums\StoreType;

/**
 * A point of sale of the network: a franchisee store (with its own tenant, VAT / UID and books) or a directly operated store (DOS).
 *
 * @property string|null $franchisee_tenant_id
 * @property string|null $contact_id
 * @property string $code
 * @property string $name
 * @property StoreType $store_type
 * @property StoreStatus $status
 * @property string $country
 * @property string|null $city
 * @property string|null $address
 * @property string|null $postal_code
 * @property SizeCluster $size_cluster
 * @property Carbon|null $opened_on
 * @property string|null $area_manager_user_id
 * @property string|null $vat_number
 * @property string|null $uid_number
 * @property string|null $link_code
 */
final class FranchiseStore extends BaseEnterpriseModel
{
    protected $table = 'frn_stores';

    protected function casts(): array
    {
        return [
            'store_type' => StoreType::class,
            'status' => StoreStatus::class,
            'size_cluster' => SizeCluster::class,
            'opened_on' => 'date',
        ];
    }

    /**
     * @return HasMany<FranchiseAgreement, $this>
     */
    public function agreements(): HasMany
    {
        return $this->hasMany(FranchiseAgreement::class, 'store_id');
    }

    /**
     * @return HasMany<SellOutReport, $this>
     */
    public function sellOutReports(): HasMany
    {
        return $this->hasMany(SellOutReport::class, 'store_id');
    }
}

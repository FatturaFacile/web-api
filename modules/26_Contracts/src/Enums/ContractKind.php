<?php

declare(strict_types=1);

namespace Modules\Contracts\Enums;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Models\SupplierContract;
use Modules\ServiceManagement\Models\ServiceContract;

/**
 * The register adds no third contract table: customer contracts are the service contracts, supplier contracts the
 * framework agreements of procurement.
 */
enum ContractKind: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Customer => ServiceContract::class,
            self::Supplier => SupplierContract::class,
        };
    }

    public function endColumn(): string
    {
        return match ($this) {
            self::Customer => 'end_date',
            self::Supplier => 'ends_at',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sends the supplies of a supplier to a cost account. With a keyword it applies only to the lines that contain it.
 */
class SupplierCostRule extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'supplier_id', 'keyword', 'cost_account_id'];
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Plm\Models\PlmChangeApproval;
use Modules\Plm\Models\PlmChangeOrder;

/**
 * @extends Factory<PlmChangeApproval>
 */
final class PlmChangeApprovalFactory extends Factory
{
    protected $model = PlmChangeApproval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'change_order_id' => PlmChangeOrder::factory(),
            'approver_id' => User::factory(),
            'sequence' => 1,
            'status' => PlmChangeApproval::Pending,
        ];
    }
}

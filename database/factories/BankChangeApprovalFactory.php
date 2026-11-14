<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\BankChangeApproval;
use Modules\SupplierPortal\Models\BankChangeRequest;

/**
 * @extends Factory<BankChangeApproval>
 */
final class BankChangeApprovalFactory extends Factory
{
    protected $model = BankChangeApproval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'request_id' => BankChangeRequest::factory(),
            'user_id' => (string) Str::uuid7(),
            'decision' => BankChangeApproval::Approved,
            'decided_at' => now(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Documents\Models\DmsApprovalTemplate;
use Modules\Documents\Models\DmsApprovalTemplateStep;

/**
 * @extends Factory<DmsApprovalTemplate>
 */
final class DmsApprovalTemplateFactory extends Factory
{
    protected $model = DmsApprovalTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Contract approval '.fake()->unique()->numerify('###'),
            'document_type' => null,
            'is_active' => true,
        ];
    }

    /**
     * A template with one step per given role, in order.
     *
     * @param  list<string>  $roles
     */
    public function withRoleSteps(array $roles): static
    {
        return $this->afterCreating(function (DmsApprovalTemplate $template) use ($roles): void {
            foreach (array_values($roles) as $index => $role) {
                DmsApprovalTemplateStep::create([
                    'tenant_id' => $template->tenant_id,
                    'template_id' => $template->id,
                    'position' => $index + 1,
                    'name' => 'Step '.($index + 1),
                    'approver_type' => 'role',
                    'approver_role' => $role,
                ]);
            }
        });
    }
}

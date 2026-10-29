<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();
        $contactId = Contact::where('tenant_id', $tenantId)->value('id') ?? Contact::factory()->create(['tenant_id' => $tenantId])->id;
        $userId = User::first()?->id ?? (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'contact_id' => $contactId,
            'project_number' => 'PRJ-'.strtoupper(fake()->unique()->bothify('??-####')),
            'name' => fake()->catchPhrase(),
            'description' => fake()->paragraph(),
            'project_type' => fake()->randomElement(['internal', 'customer', 'construction', 'consulting', 'software_dev', 'maintenance', 'capex']),
            'billing_method' => fake()->randomElement(['fixed_price', 'time_and_materials', 'cost_plus', 'milestone_based']),
            'status' => 'planned',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addMonths(6)->toDateString(),
            'project_manager_user_id' => $userId,
            'contract_value' => fake()->randomFloat(2, 25000, 500000),
            'currency' => 'EUR',
            'progress_percentage' => 0.0,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => 'draft',
            'progress_percentage' => 0.0,
        ]);
    }

    public function planned(): static
    {
        return $this->state(fn () => [
            'status' => 'planned',
            'progress_percentage' => 0.0,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'actual_start_date' => now()->subMonth()->toDateString(),
            'progress_percentage' => 35.0,
        ]);
    }

    public function onHold(): static
    {
        return $this->state(fn () => [
            'status' => 'on_hold',
            'progress_percentage' => 40.0,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'progress_percentage' => 100.0,
            'actual_end_date' => now()->toDateString(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'closed',
            'progress_percentage' => 100.0,
            'actual_end_date' => now()->subDays(5)->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
        ]);
    }

    public function overBudget(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'progress_percentage' => 60.0,
        ]);
    }

    public function delayed(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'planned_end_date' => now()->subDays(10)->toDateString(),
            'progress_percentage' => 50.0,
        ]);
    }

    public function highRisk(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'description' => 'High risk project with volatile deliverables',
        ]);
    }

    public function lowMargin(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'contract_value' => 10000.00,
        ]);
    }

    public function profitable(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'contract_value' => 500000.00,
        ]);
    }

    public function lossMaking(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'contract_value' => 25000.00,
        ]);
    }
}
